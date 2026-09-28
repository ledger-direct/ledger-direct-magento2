<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Block;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Service\OrderPaymentService;
use InvalidArgumentException;
use Magento\Framework\DataObject;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Api\Data\OrderPaymentInterface;

/**
 * The payment information of a LedgerDirect order: what was quoted, what arrived, which
 * transaction - on the admin order view, the customer's order view and in the order emails.
 *
 * Everything shown is read from the stored PaymentIntent; the amounts are the core's plain
 * decimals and the verdict (partial, wrong asset, settled) is the core's SettlementPolicy, so
 * the merchant sees exactly what the payment page told the customer. Nothing is stored or
 * computed here beyond formatting.
 */
class Info extends \Magento\Payment\Block\Info
{
    private const EXPLORER_BY_NETWORK = [
        'mainnet' => 'https://livenet.xrpl.org/transactions/',
        'testnet' => 'https://testnet.xrpl.org/transactions/',
    ];

    /**
     * @var string
     */
    protected $_template = 'Hardcastle_LedgerDirect::info/xrpl.phtml';

    /**
     * @var OrderPaymentService
     */
    private OrderPaymentService $orderPaymentService;

    /**
     * @var SettlementPolicy
     */
    private SettlementPolicy $settlementPolicy;

    /**
     * @var TimezoneInterface
     */
    private TimezoneInterface $localeDate;

    /**
     * @var PaymentIntent|null
     */
    private ?PaymentIntent $intent = null;

    /**
     * @var bool
     */
    private bool $intentRead = false;

    /**
     * @param Context $context
     * @param OrderPaymentService $orderPaymentService
     * @param SettlementPolicy $settlementPolicy
     * @param TimezoneInterface $localeDate
     * @param array $data
     */
    public function __construct(
        Context $context,
        OrderPaymentService $orderPaymentService,
        SettlementPolicy $settlementPolicy,
        TimezoneInterface $localeDate,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->orderPaymentService = $orderPaymentService;
        $this->settlementPolicy = $settlementPolicy;
        $this->localeDate = $localeDate;
    }

    /**
     * The order's stored intent, or null on a quote payment or an order never prepared for XRPL
     *
     * @return PaymentIntent|null
     */
    public function getPaymentIntent(): ?PaymentIntent
    {
        if ($this->intentRead) {
            return $this->intent;
        }

        $this->intentRead = true;
        $payment = $this->getInfo();

        if (!$payment instanceof OrderPaymentInterface) {
            return null;
        }

        try {
            $this->intent = $this->orderPaymentService->readPaymentIntentOf($payment);
        } catch (InvalidArgumentException $exception) {
            $this->intent = null;
        }

        return $this->intent;
    }

    /**
     * The block explorer page of the recorded transaction, if any
     *
     * @return string|null
     */
    public function getExplorerUrl(): ?string
    {
        $intent = $this->getPaymentIntent();

        if ($intent === null || $intent->hash === null) {
            return null;
        }

        $base = self::EXPLORER_BY_NETWORK[$intent->network] ?? null;

        return $base === null ? null : $base . $intent->hash;
    }

    /**
     * @inheritdoc
     */
    protected function _prepareSpecificInformation($transport = null)
    {
        $transport = parent::_prepareSpecificInformation($transport);
        $intent = $this->getPaymentIntent();

        if ($intent === null) {
            return $transport;
        }

        $asset = $intent->baseAsset;
        $rows = [
            (string) __('Asset') => sprintf('%s (%s %s)', $asset, $intent->chain, $intent->network),
            (string) __('Amount requested') => $intent->amountRequestedValue() . ' ' . $asset,
            (string) __('Exchange rate') => $this->formatRate($intent->exchangeRate) . ' ' . $intent->pairing,
            (string) __('Destination account') => $intent->destinationAccount,
            (string) __('Destination tag') => (string) $intent->destinationTag,
        ];

        if (is_array($intent->amountRequested)) {
            $rows[(string) __('Issuer')] = (string) $intent->amountRequested['issuer'];
        }

        if ($intent->expiry !== null && $intent->amountPaid === null) {
            $rows[(string) __('Quote valid until')] = $this->localeDate->formatDateTime(
                (new \DateTimeImmutable())->setTimestamp($intent->expiry)
            );
        }

        $status = PaymentStatus::fromIntent($intent, $this->settlementPolicy);
        $rows[(string) __('Payment status')] = $this->statusLabel($status->state());

        if ($intent->amountPaid !== null) {
            $rows[(string) __('Amount received')] = $this->describeReceived($intent);

            $shortfall = $this->settlementPolicy->shortfall($intent);
            if ($shortfall !== null) {
                $rows[(string) __('Amount outstanding')] = $shortfall . ' ' . $asset;
            }
        }

        if ($intent->hash !== null) {
            $rows[(string) __('Transaction')] = $intent->hash;
        }

        if ($intent->ctid !== null) {
            $rows[(string) __('CTID')] = $intent->ctid;
        }

        return $transport->addData($rows);
    }

    /**
     * What arrived, naming the other token when it is not the quoted one
     *
     * @param PaymentIntent $intent
     * @return string
     */
    private function describeReceived(PaymentIntent $intent): string
    {
        $value = (string) $intent->amountPaidValue();

        if (!$this->settlementPolicy->isWrongAsset($intent) || !is_array($intent->amountPaid)) {
            return $value . ' ' . $intent->baseAsset;
        }

        return (string) __(
            '%1 %2 from issuer %3 - not the requested asset, not credited',
            $value,
            $this->currencyName((string) $intent->amountPaid['currency']),
            (string) $intent->amountPaid['issuer']
        );
    }

    /**
     * An XRPL currency code as a name: the 40-hex form decodes to its ASCII letters, anything else stays
     *
     * @param string $currency
     * @return string
     */
    public function currencyName(string $currency): string
    {
        if (preg_match('/^[0-9A-F]{40}$/i', $currency)) {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            $decoded = rtrim((string) hex2bin($currency), "\0");

            if ($decoded !== '' && preg_match('/^[A-Za-z0-9]+$/', $decoded)) {
                return $decoded;
            }
        }

        return $currency;
    }

    /**
     * The contract state as the merchant reads it
     *
     * @param string $state
     * @return string
     */
    private function statusLabel(string $state): string
    {
        return (string) match ($state) {
            PaymentStatus::SETTLED => __('Settled'),
            PaymentStatus::PARTIAL => __('Partially paid'),
            PaymentStatus::WRONG_ASSET => __('Payment in the wrong asset'),
            PaymentStatus::EXPIRED => __('Quote expired, nothing received'),
            default => __('Waiting for payment'),
        };
    }

    /**
     * The rate without PHP's float tail or exponent notation
     *
     * @param float $rate
     * @return string
     */
    private function formatRate(float $rate): string
    {
        $formatted = number_format($rate, 6, '.', '');

        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }
}
