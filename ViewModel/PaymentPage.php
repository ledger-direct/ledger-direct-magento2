<?php declare(strict_types=1);
/**
 * Copyright (c) Alexander Busse | Hardcastle Technologies.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Hardcastle\LedgerDirect\ViewModel;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Hardcastle\LedgerDirect\Api\Data\XrpPaymentInterface;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Payment\PaymentUri;
use Hardcastle\LedgerDirect\Core\Presentation\AccentColor;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplAmount;
use Hardcastle\LedgerDirect\Helper\SystemConfig;
use Hardcastle\LedgerDirect\Model\Config\Source\LogoMode;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Framework\View\Element\Template;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Turns what the controller hands the block into the plain values the payment page
 * renders - the presenter behind view/frontend/templates/payment/index.phtml.
 *
 * Every amount is the core's plain decimal as the DTO carries it (amount requested,
 * paid, outstanding); nothing here rounds. The one computation is the progress bar's
 * width, never shown as a number, and the one formatting is the exchange rate's,
 * which is not an amount. The payment request behind the QR code and the accent rule
 * come from the core (PaymentUri, AccentColor), so every LedgerDirect plugin renders
 * the same request and applies the same rule.
 */
class PaymentPage implements ArgumentInterface
{
    private const EXPLORER = [
        'mainnet' => 'https://livenet.xrpl.org/transactions/',
        'testnet' => 'https://testnet.xrpl.org/transactions/',
    ];

    private const ASSET_LABELS = [
        'xrp-payment' => 'XRP',
        'rlusd-payment' => 'RLUSD',
        'usdc-payment' => 'USDC',
    ];

    /**
     * How many decimals the exchange rate is shown with - an oracle average with a
     * float tail, cosmetic beyond a few places.
     */
    private const RATE_DECIMALS = 6;

    /**
     * @var SystemConfig
     */
    private SystemConfig $config;

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @var PriceCurrencyInterface
     */
    private PriceCurrencyInterface $priceCurrency;

    /**
     * @var UrlInterface
     */
    private UrlInterface $urlBuilder;

    /**
     * @var AssetRepository
     */
    private AssetRepository $assetRepository;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @param SystemConfig $config
     * @param ScopeConfigInterface $scopeConfig
     * @param PriceCurrencyInterface $priceCurrency
     * @param UrlInterface $urlBuilder
     * @param AssetRepository $assetRepository
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        SystemConfig $config,
        ScopeConfigInterface $scopeConfig,
        PriceCurrencyInterface $priceCurrency,
        UrlInterface $urlBuilder,
        AssetRepository $assetRepository,
        StoreManagerInterface $storeManager
    ) {
        $this->config = $config;
        $this->scopeConfig = $scopeConfig;
        $this->priceCurrency = $priceCurrency;
        $this->urlBuilder = $urlBuilder;
        $this->assetRepository = $assetRepository;
        $this->storeManager = $storeManager;
    }

    /**
     * Everything the template renders, from the data the controller set on the block
     *
     * @param Template $block
     * @return array<string, mixed>
     */
    public function forBlock(Template $block): array
    {
        /** @var XrpPaymentInterface $info */
        $info = $block->getData('payment_info');
        /** @var PaymentIntent $intent */
        $intent = $block->getData('payment_intent');
        /** @var PaymentStatus $status */
        $status = $block->getData('payment_status');

        $state = $status->state();
        $amountRequested = $info->getAmountRequested();
        $amountPaid = $info->getAmountPaid();
        $shortfall = $info->getAmountOutstanding();
        $isToken = $info->getType() !== 'xrp-payment';
        $amountDue = $state === PaymentStatus::PARTIAL && $shortfall !== null ? $shortfall : $amountRequested;
        $paymentUri = PaymentUri::forIntent($intent, $amountDue);
        $storeName = $this->storeName();

        return [
            'state' => $state,
            'seconds_left' => $status->secondsLeft,
            'has_expiry' => (bool) $block->getData('has_expiry'),
            'quote_seconds' => $this->config->getQuoteExpirySeconds(),
            'asset' => self::ASSET_LABELS[$info->getType()] ?? 'XRP',
            'network' => $info->getNetwork(),
            'is_testnet' => $info->getNetwork() !== 'mainnet',
            'explorer_base' => self::EXPLORER[$info->getNetwork()] ?? self::EXPLORER['testnet'],
            'destination_account' => $info->getDestinationAccount(),
            'destination_tag' => $info->getDestinationTag(),
            'issuer' => $isToken ? (string) $info->getIssuer() : null,
            'currency' => $isToken ? (string) $info->getCurrency() : null,
            'amount' => $amountRequested,
            'amount_paid' => $amountPaid,
            'shortfall' => $shortfall,
            'amount_due' => $amountDue,
            'amount_drops' => $isToken ? null : XrplAmount::xrpToDrops($amountDue),
            'payment_uri' => $paymentUri,
            'qr_data_uri' => $this->qrDataUri($paymentUri),
            'paid_share' => $this->paidShare($amountPaid, $amountRequested, $state),
            'rate_display' => $this->rate($info->getExchangeRate()),
            'currency_code' => $info->getCurrencyCode(),
            'fiat_display' => (string) $this->priceCurrency->format(
                $info->getPrice(),
                false,
                PriceCurrencyInterface::DEFAULT_PRECISION,
                null,
                $info->getCurrencyCode()
            ),
            'order_number' => $info->getOrderNumber(),
            'store_name' => $storeName,
            'accent' => AccentColor::sanitize($this->config->getPaymentPageAccentColor()),
            'logo' => $this->logo($storeName),
            'xaman_key' => $this->config->getXamanApiKey(),
            'wc_project' => $this->config->getWalletConnectProjectId(),
            'poll_url' => (string) $block->getData('poll_url'),
            'refresh_url' => (string) $block->getData('refresh_url'),
            'page_url' => (string) $block->getData('page_url'),
            'redirect_url' => (string) $block->getData('redirect_url'),
            'home_url' => $this->urlBuilder->getUrl(''),
            'cart_url' => $this->urlBuilder->getUrl('checkout/cart'),
            'access_key' => (string) $block->getData('access_key'),
            'form_key' => (string) $block->getData('form_key'),
            'order_id' => $info->getOrderId(),
        ];
    }

    /**
     * The exchange rate as a plain decimal: at most six places, no trailing zeros, no exponent
     *
     * @param float $rate
     * @return string
     */
    public function rate(float $rate): string
    {
        $decimal = number_format($rate, self::RATE_DECIMALS, '.', '');

        return str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;
    }

    /**
     * The progress bar's width in whole per cent - only a partial payment has one
     *
     * @param string|null $amountPaid
     * @param string $amountRequested
     * @param string $state
     * @return int
     */
    private function paidShare(?string $amountPaid, string $amountRequested, string $state): int
    {
        if ($state !== PaymentStatus::PARTIAL || $amountPaid === null || (float) $amountRequested <= 0.0) {
            return 0;
        }

        return (int) max(0, min(100, floor((float) $amountPaid / (float) $amountRequested * 100)));
    }

    /**
     * The QR code the page shows without JavaScript - the script redraws the same request
     *
     * @param string $paymentUri
     * @return string
     */
    private function qrDataUri(string $paymentUri): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(320, 1), new SvgImageBackEnd()));

        return 'data:image/svg+xml;base64,' . base64_encode($writer->writeString($paymentUri));
    }

    /**
     * Which logo the header shows: the store's, an uploaded one, or a monogram.
     *
     * The uploaded picture is resolved below media/ledger_direct/logo only - never from
     * a URL a merchant typed. When it cannot be resolved, the monogram takes over.
     *
     * @param string $storeName
     * @return array{mode: string, url: string|null, monogram: string}
     */
    private function logo(string $storeName): array
    {
        $mode = $this->config->getPaymentPageLogoMode();
        $url = null;

        if ($mode === LogoMode::SHOP) {
            $url = $this->storeLogoUrl();
        } elseif ($mode === LogoMode::CUSTOM) {
            $file = $this->config->getPaymentPageLogoFile();
            $isSafe = $file !== '' && preg_match('#^[A-Za-z0-9._/-]+$#', $file) === 1 && !str_contains($file, '..');
            $url = $isSafe ? $this->mediaUrl('ledger_direct/logo/' . $file) : null;
        }

        if ($url === null && $mode !== LogoMode::NONE) {
            $mode = LogoMode::NONE;
        }

        return [
            'mode' => $mode,
            'url' => $url,
            'monogram' => $this->monogram($storeName),
        ];
    }

    /**
     * The store's own logo: the uploaded one from design/header/logo_src, else the theme's
     *
     * @return string|null
     */
    private function storeLogoUrl(): ?string
    {
        $configured = (string) $this->scopeConfig->getValue(
            'design/header/logo_src',
            ScopeInterface::SCOPE_STORE
        );

        if ($configured !== '') {
            return $this->mediaUrl('logo/' . $configured);
        }

        try {
            return $this->assetRepository->getUrl('images/logo.svg');
        } catch (\Throwable $exception) {
            return null;
        }
    }

    /**
     * A URL below the media directory
     *
     * @param string $path
     * @return string
     */
    private function mediaUrl(string $path): string
    {
        return $this->urlBuilder->getBaseUrl(['_type' => UrlInterface::URL_TYPE_MEDIA]) . $path;
    }

    /**
     * The store's name as customers see it
     *
     * @return string
     */
    private function storeName(): string
    {
        $configured = trim((string) $this->scopeConfig->getValue(
            'general/store_information/name',
            ScopeInterface::SCOPE_STORE
        ));

        if ($configured !== '') {
            return $configured;
        }

        try {
            return (string) $this->storeManager->getStore()->getName();
        } catch (\Throwable $exception) {
            return '';
        }
    }

    /**
     * The first letter of the store name, upper-cased, multibyte-safe; a placeholder when there is none
     *
     * @param string $storeName
     * @return string
     */
    public function monogram(string $storeName): string
    {
        $trimmed = trim($storeName);

        if ($trimmed === '') {
            return '·';
        }

        return mb_strtoupper(mb_substr($trimmed, 0, 1));
    }
}
