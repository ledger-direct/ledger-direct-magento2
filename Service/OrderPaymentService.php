<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntentService;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncService;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplRpcException;
use InvalidArgumentException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
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
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderFactory $orderFactory
     * @param PaymentIntentService $paymentIntentService
     * @param SyncService $syncService
     * @param LoggerInterface $logger
     */
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        OrderFactory $orderFactory,
        PaymentIntentService $paymentIntentService,
        SyncService $syncService,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->orderFactory = $orderFactory;
        $this->paymentIntentService = $paymentIntentService;
        $this->syncService = $syncService;
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
     * Get an order by its increment ID
     *
     * @param string $orderNumber
     * @return OrderInterface
     */
    public function getOrderByOrderNumber(string $orderNumber): OrderInterface
    {
        return $this->orderFactory->create()->loadByIncrementId($orderNumber);
    }

    /**
     * Quote the order in the asset its payment method stands for and store the PaymentIntent
     *
     * The payment page calls this on every view, so an intent that is still valid is kept
     * as it is. Once its quote has expired the price is fetched again, but the destination
     * account and tag are kept: the customer may already be looking at them, or have a
     * payment in flight. A settled intent is never touched.
     *
     * @param OrderInterface $order
     * @return PaymentIntent
     */
    public function prepareOrderPaymentForXrpl(OrderInterface $order): PaymentIntent
    {
        $existing = $this->readReusablePaymentIntent($order);

        if ($existing !== null && ($existing->hash !== null || !$this->isExpired($existing))) {
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
     * Sync the merchant's incoming XRPL transactions and settle the order's intent on a match
     *
     * @param OrderInterface $order
     * @return PaymentIntent|null the fulfilled intent, or null while no transaction on the
     *     order's tag pays it (nothing arrived, or only non-payments / payments in another
     *     asset class, which the core skips)
     */
    public function syncOrderTransactionWithXrpl(OrderInterface $order): ?PaymentIntent
    {
        $intent = $this->readPaymentIntent($order);

        if ($intent === null) {
            return null;
        }

        if ($intent->hash !== null) {
            return $intent;
        }

        try {
            $this->syncService->syncTransactions($intent->destinationAccount, $intent->network);
        } catch (XrplRpcException $exception) {
            // The node being unreachable must not take the payment page down: the order simply
            // stays pending until the next check succeeds.
            $this->logger->warning('LedgerDirect: XRPL sync failed, order stays pending', [
                'order' => $order->getIncrementId(),
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        // Which of the transactions on this tag pays the intent (same asset class, newest
        // first) is the core's decision; whatever comes back has a decodable delivered amount.
        $transaction = $this->syncService->findTransactionFor($intent);

        if ($transaction === null) {
            return null;
        }

        $fulfilledIntent = $intent->withFulfillment(
            $transaction->hash,
            $transaction->getDeliveredAmount(),
            $transaction->ctid
        );

        $this->persistPaymentIntent($order, $fulfilledIntent);

        return $fulfilledIntent;
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
