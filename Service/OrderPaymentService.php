<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

use Exception;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntentService;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncService;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncThrottle;
use InvalidArgumentException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Psr\Log\LoggerInterface;

/**
 * The Magento side of a LedgerDirect payment: loading orders and persisting the payment
 * record on the order's payment.
 *
 * Everything about *what* is owed - exchange rate, requested amount, destination tag,
 * matching an on-ledger payment - comes from hardcastle/ledger-direct-core and is not
 * recomputed here.
 */
class OrderPaymentService
{
    /**
     * Storage key inside the order payment's additional_data. Not part of the cross-plugin
     * contract (the PaymentIntent it holds is), so it stays as it was.
     */
    public const ADDITIONAL_DATA_KEY = 'xrpl';

    /**
     * The payment method codes this module owns
     */
    public const PAYMENT_METHODS = ['xrp_payment', 'xrpl_rlusd_payment', 'xrpl_usdc_payment'];

    private const BASE_ASSET_BY_PAYMENT_METHOD = [
        'xrp_payment' => 'XRP',
        'xrpl_rlusd_payment' => 'RLUSD',
        'xrpl_usdc_payment' => 'USDC',
    ];

    /**
     * @var OrderRepositoryInterface
     */
    private OrderRepositoryInterface $orderRepository;

    /**
     * @var OrderFactory
     */
    private OrderFactory $orderFactory;

    /**
     * @var PaymentIntentService
     */
    private PaymentIntentService $paymentIntentService;

    /**
     * @var SyncService
     */
    private SyncService $syncService;

    /**
     * @var SyncThrottle
     */
    private SyncThrottle $syncThrottle;

    /**
     * @var SettlementPolicy
     */
    private SettlementPolicy $settlementPolicy;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderFactory $orderFactory
     * @param PaymentIntentService $paymentIntentService
     * @param SyncService $syncService
     * @param SyncThrottle $syncThrottle
     * @param SettlementPolicy $settlementPolicy
     * @param LoggerInterface $logger
     */
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        OrderFactory $orderFactory,
        PaymentIntentService $paymentIntentService,
        SyncService $syncService,
        SyncThrottle $syncThrottle,
        SettlementPolicy $settlementPolicy,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->orderFactory = $orderFactory;
        $this->paymentIntentService = $paymentIntentService;
        $this->syncService = $syncService;
        $this->syncThrottle = $syncThrottle;
        $this->settlementPolicy = $settlementPolicy;
        $this->logger = $logger;
    }

    /**
     * Get an order by its entity ID
     *
     * @param int $orderId
     * @return OrderInterface
     */
    public function getOrderById(int $orderId): OrderInterface
    {
        return $this->orderRepository->get($orderId);
    }

    /**
     * Whether the order is a LedgerDirect order that still waits for money on the ledger
     *
     * Anything else - processing after the invoice, canceled by Magento's pending-payment
     * cron or by the merchant, closed, on hold - is over as far as the payment page is
     * concerned, whatever the ledger says.
     *
     * @param OrderInterface $order
     * @return bool
     */
    public function isAwaitingPayment(OrderInterface $order): bool
    {
        return $order->getState() === Order::STATE_PENDING_PAYMENT
            && (float) $order->getTotalDue() > 0.0;
    }

    /**
     * The same, read fresh from the database
     *
     * The repository hands back the object it already loaded in this request; after a
     * settlement in the same request that object is stale, and a status answer built from it
     * would send the redirect one poll too late.
     *
     * @param int $orderId
     * @return bool
     */
    public function isAwaitingPaymentById(int $orderId): bool
    {
        $order = $this->orderFactory->create()->load($orderId);

        return $order->getId() !== null && $this->isAwaitingPayment($order);
    }

    /**
     * Quote the order in the asset its payment method stands for and store the PaymentIntent
     *
     * The payment page calls this on every view. A stored intent is handed back as it is,
     * expired or not: the customer has to see that the quote has run out and ask for a new
     * one, rather than the amount silently changing under them on a reload. Only with
     * $requote does an expired quote get a new price, and even then the destination account
     * and tag are kept - the customer may already be looking at them, or have a payment in
     * flight. A fulfilled intent is never touched.
     *
     * @param OrderInterface $order
     * @param bool $requote fetch a new price for an expired quote
     * @return PaymentIntent
     */
    public function prepareOrderPaymentForXrpl(OrderInterface $order, bool $requote = false): PaymentIntent
    {
        $existing = $this->readReusablePaymentIntent($order);

        if ($existing !== null && ($existing->hash !== null || !$requote || !$this->isExpired($existing))) {
            return $existing;
        }

        $paymentMethod = (string) $order->getPayment()->getMethod();
        $baseAsset = self::BASE_ASSET_BY_PAYMENT_METHOD[$paymentMethod] ?? null;

        if ($baseAsset === null) {
            throw new InvalidArgumentException('Unsupported payment method: ' . $paymentMethod);
        }

        $intent = $this->paymentIntentService->quoteForOrder(
            (float) $order->getTotalDue(),
            (string) $order->getOrderCurrencyCode(),
            $baseAsset,
            $existing
        );

        $this->persistPaymentIntent($order, $intent);

        return $intent;
    }

    /**
     * Sync the merchant's incoming XRPL transactions and record what pays the order's intent
     *
     * What pays is the core's decision: every payment in the quoted asset on the tag counts
     * and they add up, so a top-up of a shortfall settles; a payment in another asset is the
     * fulfillment only while nothing in the right one has arrived, so the page can say
     * "wrong token". The intent records the hash and ctid of the newest contributing
     * transaction.
     *
     * @param OrderInterface $order
     * @param bool $throttled skip the node request when this receiving account was synced
     *     within SyncThrottle's interval and match against what is stored locally - the
     *     payment page and the status endpoint pass true, the cron never does
     * @return PaymentIntent|null the fulfilled intent, or null while nothing payable has
     *     arrived: no transaction on the tag yet, only ones that delivered nothing
     *     measurable, or only ones in the other asset class - the core logs and skips those
     */
    public function syncOrderTransactionWithXrpl(OrderInterface $order, bool $throttled = false): ?PaymentIntent
    {
        $intent = $this->readPaymentIntent($order);

        if ($intent === null) {
            return null;
        }

        if ($this->isFullyPaid($intent)) {
            return $intent;
        }

        $this->syncLedger($intent->destinationAccount, $intent->network, $throttled);

        return $this->matchOrder($order);
    }

    /**
     * The "match" half of syncOrderTransactionWithXrpl()
     *
     * What the local transaction table holds for this order's tag, and whether it pays.
     * The cron syncs each receiving account once and then matches every open order against
     * the table - one node request per account, not one per order.
     *
     * A stored fulfillment ends the matching only once it settles the order. A partial
     * payment, or one in the wrong asset, is matched again every time: the customer may
     * still send the rest, or the right token, and that must not be locked out by the
     * first hit.
     *
     * @param OrderInterface $order
     * @return PaymentIntent|null as syncOrderTransactionWithXrpl()
     */
    public function matchOrder(OrderInterface $order): ?PaymentIntent
    {
        $intent = $this->readPaymentIntent($order);

        if ($intent === null) {
            return null;
        }

        if ($this->isFullyPaid($intent)) {
            return $intent;
        }

        $fulfilledIntent = $this->syncService->findFulfillmentFor($intent)?->applyTo($intent);

        if ($fulfilledIntent === null) {
            return null;
        }

        // The status endpoint asks every few seconds; an unchanged hit is not written again.
        if (!$this->sameFulfillment($intent, $fulfilledIntent)) {
            $this->persistPaymentIntent($order, $fulfilledIntent);
        }

        return $fulfilledIntent;
    }

    /**
     * Pull the account's transactions from the ledger into the local table
     *
     * Throttled per receiving account and network, not per order: the sync fetches the whole
     * account in one go and matching afterwards is local, so ten waiting customers inside
     * one window cost one node request. The mark is set before the request, not after a
     * successful one - a node that is down must not be hit harder than one that answers.
     *
     * A failed sync is logged, not thrown: the payment page must not go down with the node,
     * and matching still runs against what is stored.
     *
     * @param string $destinationAccount
     * @param string $network
     * @param bool $throttled
     * @return void
     */
    public function syncLedger(string $destinationAccount, string $network, bool $throttled): void
    {
        if ($throttled) {
            if (!$this->syncThrottle->shouldSync($network, $destinationAccount)) {
                return;
            }

            $this->syncThrottle->markSynced($network, $destinationAccount);
        }

        try {
            $this->syncService->syncTransactions($destinationAccount, $network);
        } catch (Exception $exception) {
            $this->logger->warning('LedgerDirect: XRPL sync failed, matching against stored transactions', [
                'destination_account' => $destinationAccount,
                'network' => $network,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * The payment record stored on the order, or null when the order was never prepared for XRPL
     *
     * Throws on a record that is not a readable schema v1 PaymentIntent: the module was never
     * released, so there is no legacy format to keep reading, and quietly ignoring an
     * unreadable record would hide a real problem behind an "unpaid" order.
     *
     * @param OrderInterface $order
     * @return PaymentIntent|null
     */
    public function readPaymentIntent(OrderInterface $order): ?PaymentIntent
    {
        $paymentIntentData = $this->readAdditionalData($order)[self::ADDITIONAL_DATA_KEY] ?? null;

        return is_array($paymentIntentData) ? PaymentIntent::fromArray($paymentIntentData) : null;
    }

    /**
     * Like readPaymentIntent(), but tolerant: on the quoting path an unreadable record means "quote from scratch"
     *
     * @param OrderInterface $order
     * @return PaymentIntent|null
     */
    private function readReusablePaymentIntent(OrderInterface $order): ?PaymentIntent
    {
        try {
            return $this->readPaymentIntent($order);
        } catch (InvalidArgumentException $exception) {
            return null;
        }
    }

    /**
     * Whether the stored fulfillment already settles the intent - the only hit that ends the matching
     *
     * @param PaymentIntent $intent
     * @return bool
     */
    private function isFullyPaid(PaymentIntent $intent): bool
    {
        return $intent->hash !== null && $this->settlementPolicy->isSettled($intent);
    }

    /**
     * Whether a fresh match found nothing beyond what the stored intent already records
     *
     * @param PaymentIntent $stored
     * @param PaymentIntent $fulfilled
     * @return bool
     */
    private function sameFulfillment(PaymentIntent $stored, PaymentIntent $fulfilled): bool
    {
        return $stored->hash === $fulfilled->hash
            && $stored->ctid === $fulfilled->ctid
            && $stored->amountPaidValue() === $fulfilled->amountPaidValue();
    }

    /**
     * Whether the intent's quote has passed its expiry
     *
     * @param PaymentIntent $intent
     * @return bool
     */
    private function isExpired(PaymentIntent $intent): bool
    {
        return $intent->expiry !== null && $intent->expiry < time();
    }

    /**
     * Write the intent to the order payment, replacing any previous record wholesale
     *
     * @param OrderInterface $order
     * @param PaymentIntent $intent
     * @return void
     */
    private function persistPaymentIntent(OrderInterface $order, PaymentIntent $intent): void
    {
        $additionalData = $this->readAdditionalData($order);
        $additionalData[self::ADDITIONAL_DATA_KEY] = $intent->toArray();

        $order->getPayment()->setAdditionalData(json_encode($additionalData, JSON_THROW_ON_ERROR));

        $this->orderRepository->save($order);
    }

    /**
     * Decode the order payment's additional_data
     *
     * @param OrderInterface $order
     * @return array
     */
    private function readAdditionalData(OrderInterface $order): array
    {
        $rawAdditionalData = $order->getPayment()->getAdditionalData();

        if (empty($rawAdditionalData)) {
            return [];
        }

        $additionalData = json_decode((string) $rawAdditionalData, true);

        return is_array($additionalData) ? $additionalData : [];
    }
}
