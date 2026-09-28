<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\ViewModel;

use Hardcastle\LedgerDirect\Api\Data\XrpPaymentInterface;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Helper\SystemConfig;
use Hardcastle\LedgerDirect\ViewModel\PaymentPage;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Repository;
use Magento\Framework\View\Element\Template;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * What the template gets to see, per payment state: the numbers a customer types into a
 * wallet come out exactly as the core states them, and the payment request behind the QR
 * code follows the amount to send.
 */
class PaymentPageTest extends TestCase
{
    private const ACCOUNT = 'raXkRCAYkqaoFYCeVej93SzCTtiAbbRzAg';
    private const NOW = 1_700_000_000;

    /** @var SystemConfig|MockObject */
    private $config;

    /** @var ScopeConfigInterface|MockObject */
    private $scopeConfig;

    /** @var StoreInterface|MockObject */
    private $store;

    private PaymentPage $viewModel;

    protected function setUp(): void
    {
        $this->config = $this->createMock(SystemConfig::class);
        $this->config->method('getQuoteExpirySeconds')->willReturn(300);
        $this->config->method('getPaymentPageLogoMode')->willReturn('shop');
        $this->config->method('getPaymentPageLogoFile')->willReturn('');
        $this->config->method('getPaymentPageAccentColor')->willReturn('#1f5eff');
        $this->config->method('getXamanApiKey')->willReturn('');
        $this->config->method('getWalletConnectProjectId')->willReturn('');

        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->scopeConfig->method('getValue')->willReturn('');

        $priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $priceCurrency->method('format')->willReturnCallback(fn ($amount) => number_format((float) $amount, 2) . ' €');

        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturnCallback(fn ($path) => 'https://shop.test/' . $path);
        $urlBuilder->method('getBaseUrl')->willReturn('https://shop.test/media/');

        $assets = $this->createMock(Repository::class);
        $assets->method('getUrl')->willReturn('https://shop.test/static/images/logo.svg');

        $this->store = $this->createMock(StoreInterface::class);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($this->store);

        $this->viewModel = new PaymentPage($this->config, $this->scopeConfig, $priceCurrency, $urlBuilder, $assets, $storeManager);
    }

    public function testAWaitingXrpOrderShowsTheRequestInDropsAndInTheQrRequest(): void
    {
        $intent = $this->xrpIntent(0.83211, self::NOW + 200);
        $view = $this->viewModel->forBlock($this->block($intent, ['amount_requested' => '0.83211']));

        self::assertSame('waiting', $view['state']);
        self::assertSame(200, $view['seconds_left']);
        self::assertSame('0.83211', $view['amount']);
        self::assertSame('0.83211', $view['amount_due']);
        self::assertSame('832110', $view['amount_drops']);
        self::assertNull($view['currency']);
        self::assertSame('https://xrplf.org//send?to=' . self::ACCOUNT . '&dt=123456&amount=0.83211', $view['payment_uri']);
        self::assertStringStartsWith('data:image/svg+xml;base64,', $view['qr_data_uri']);
        self::assertSame('XRP', $view['asset']);
        self::assertSame('https://testnet.xrpl.org/transactions/', $view['explorer_base']);
        self::assertSame(0, $view['paid_share']);
        self::assertSame('#1f5eff', $view['accent']);
    }

    public function testAPartialPaymentAsksForTheShortfall(): void
    {
        $intent = $this->xrpIntent(15.06378, self::NOW + 200)->withFulfillment('AA', 5.0, 'C1');
        $view = $this->viewModel->forBlock($this->block($intent, [
            'amount_requested' => '15.06378', 'amount_paid' => '5', 'amount_outstanding' => '10.06378',
        ]));

        self::assertSame('partial', $view['state']);
        self::assertSame('10.06378', $view['amount_due']);
        self::assertSame('10063780', $view['amount_drops']);
        self::assertStringContainsString('&amount=10.06378', $view['payment_uri']);
        self::assertSame(33, $view['paid_share']);
    }

    public function testATokenOrderCarriesCurrencyAndIssuer(): void
    {
        $intent = PaymentIntent::quote(
            type: 'usdc-payment', chain: 'XRPL', network: 'mainnet', baseAsset: 'USDC', quoteCurrency: 'EUR',
            pairing: 'USDC/EUR', exchangeRate: 0.92,
            amountRequested: ['currency' => '5553444300000000000000000000000000000000', 'value' => '12.50', 'issuer' => 'rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt'],
            destinationAccount: self::ACCOUNT, destinationTag: 123456, expiry: self::NOW + 200,
        );
        $view = $this->viewModel->forBlock($this->block($intent, [
            'type' => 'usdc-payment', 'network' => 'mainnet', 'amount_requested' => '12.50',
            'currency' => '5553444300000000000000000000000000000000', 'issuer' => 'rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt',
        ]));

        self::assertSame('USDC', $view['asset']);
        self::assertNull($view['amount_drops']);
        self::assertSame('5553444300000000000000000000000000000000', $view['currency']);
        self::assertSame('rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt', $view['issuer']);
        self::assertStringContainsString('&amount=12.50&currency=5553444300000000000000000000000000000000&issuer=rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt', $view['payment_uri']);
        self::assertSame('https://livenet.xrpl.org/transactions/', $view['explorer_base']);
        self::assertFalse($view['is_testnet']);
    }

    public function testTheRateIsAPlainDecimalAndTheOnlyFormattedNumber(): void
    {
        self::assertSame('1.201763', PaymentPage::rate(1.2017633333333));
        self::assertSame('0.00001', PaymentPage::rate(0.00001));
        self::assertSame('2', PaymentPage::rate(2.0));
    }

    public function testTheStoreLogoFallsBackToTheThemesAndTheMonogramIsTheFirstLetter(): void
    {
        $this->store->method('getName')->willReturn('öko-laden');
        $view = $this->viewModel->forBlock($this->block($this->xrpIntent(1.0, self::NOW + 200), ['amount_requested' => '1']));

        self::assertSame('shop', $view['logo']['mode']);
        self::assertSame('https://shop.test/static/images/logo.svg', $view['logo']['url']);
        self::assertSame('Ö', $view['logo']['monogram']);
        self::assertSame('·', PaymentPage::monogram('  '));
    }

    private function xrpIntent(float $amount, ?int $expiry): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'xrp-payment', chain: 'XRPL', network: 'testnet', baseAsset: 'XRP', quoteCurrency: 'EUR',
            pairing: 'XRP/EUR', exchangeRate: 1.27, amountRequested: $amount,
            destinationAccount: self::ACCOUNT, destinationTag: 123456, expiry: $expiry,
        );
    }

    /**
     * @param array<string, mixed> $info the DTO's values that matter for the case
     */
    private function block(PaymentIntent $intent, array $info): Template
    {
        $dto = $this->createMock(XrpPaymentInterface::class);
        $dto->method('getType')->willReturn($info['type'] ?? 'xrp-payment');
        $dto->method('getNetwork')->willReturn($info['network'] ?? 'testnet');
        $dto->method('getAmountRequested')->willReturn($info['amount_requested']);
        $dto->method('getAmountPaid')->willReturn($info['amount_paid'] ?? null);
        $dto->method('getAmountOutstanding')->willReturn($info['amount_outstanding'] ?? null);
        $dto->method('getIssuer')->willReturn($info['issuer'] ?? null);
        $dto->method('getCurrency')->willReturn($info['currency'] ?? null);
        $dto->method('getDestinationAccount')->willReturn(self::ACCOUNT);
        $dto->method('getDestinationTag')->willReturn(123456);
        $dto->method('getExchangeRate')->willReturn(1.27);
        $dto->method('getPrice')->willReturn(1.0);
        $dto->method('getCurrencyCode')->willReturn('EUR');
        $dto->method('getOrderNumber')->willReturn('000000042');
        $dto->method('getOrderId')->willReturn(42);

        $block = $this->getMockBuilder(Template::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $block->setData('payment_info', $dto);
        $block->setData('payment_intent', $intent);
        $block->setData('payment_status', PaymentStatus::fromIntent($intent, new SettlementPolicy(), self::NOW));
        $block->setData('has_expiry', $intent->expiry !== null);
        $block->setData('poll_url', 'https://shop.test/ledger-direct/payment/status/id/42/key/k');
        $block->setData('refresh_url', 'https://shop.test/ledger-direct/payment/refresh');
        $block->setData('page_url', 'https://shop.test/ledger-direct/payment/index/id/42/key/k');
        $block->setData('redirect_url', 'https://shop.test/sales/guest/view');
        $block->setData('access_key', 'k');
        $block->setData('form_key', 'f');

        return $block;
    }
}
