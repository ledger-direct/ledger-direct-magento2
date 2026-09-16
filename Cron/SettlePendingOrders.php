<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Cron;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Service\OrderPaymentService;
use Hardcastle\LedgerDirect\Service\OrderSettlementService;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Settles LedgerDirect orders whose payment arrived while nobody was looking at the payment
 * page. Without this, an order is only ever synced when the customer's page polls - a
 * customer who closes the tab after sending would never see their order confirmed.
 *
 * One node request per receiving account and network, then every open order matched
 * against the local transaction table - not one sync per order. Unthrottled: this is the
 * safety net on its own schedule, the throttle is for the payment page that anyone can poll.
 *
 * Which accounts to sync is read from the open orders themselves, not from the
 * configuration: a shop with a test phase has orders on both networks, and an order quoted
 * against an earlier receiving address still has to settle after the merchant changed it.
 */
class SettlePendingOrders
{
    /**
     * @var CollectionFactory
     */
    private CollectionFactory $orderCollectionFactory;

    /**
     * @var OrderPaymentService
     */
    private OrderPaymentService $orderPaymentService;

    /**
     * @var OrderSettlementService
     */
    private OrderSettlementService $settlementService;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param CollectionFactory $orderCollectionFactory
     * @param OrderPaymentService $orderPaymentService
     * @param OrderSettlementService $settlementService
     * @param LoggerInterface $logger
     */
    public function __construct(
        CollectionFactory $orderCollectionFactory,
        OrderPaymentService $orderPaymentService,
        OrderSettlementService $settlementService,
        LoggerInterface $logger
    ) {
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->orderPaymentService = $orderPaymentService;
        $this->settlementService = $settlementService;
        $this->logger = $logger;
    }

    /**
     * Sync each receiving account once, then match and settle every order still waiting for its payment
     *
     * @return void
     */
    public function execute(): void
    {
        $orders = $this->orderCollectionFactory->create();
        // The payment-incomplete status lives under the same state, so those orders are included.
        $orders->join(['payment' => 'sales_order_payment'], 'main_table.entity_id = payment.parent_id', [])
            ->addFieldToFilter('payment.method', ['in' => OrderPaymentService::PAYMENT_METHODS])
            ->addFieldToFilter('main_table.state', Order::STATE_PENDING_PAYMENT);

        $accounts = [];
        $matchable = [];

        /** @var Order $order */
        foreach ($orders as $order) {
            $intent = $this->readIntent($order);

            if ($intent === null) {
                continue;
            }

            $accounts[$intent->network . '|' . $intent->destinationAccount] = [
                $intent->destinationAccount,
                $intent->network,
            ];
            $matchable[] = $order;
        }

        foreach ($accounts as [$account, $network]) {
            $this->orderPaymentService->syncLedger($account, $network, false);
        }

        $settled = 0;

        foreach ($matchable as $order) {
            try {
                $intent = $this->orderPaymentService->matchOrder($order);

                if ($intent !== null && $this->settlementService->settle($order, $intent)->isSettled()) {
                    ++$settled;
                }
            } catch (Throwable $exception) {
                // One order must not stop the others; the next run retries it.
                $this->logger->error('LedgerDirect: settling order failed', [
                    'order' => $order->getIncrementId(),
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        $this->logger->info('LedgerDirect: scheduled settlement run', [
            'accounts_synced' => count($accounts),
            'checked' => count($matchable),
            'settled' => $settled,
        ]);
    }

    /**
     * The order's stored intent, or null (logged) when it has none or an unreadable one
     *
     * @param Order $order
     * @return PaymentIntent|null
     */
    private function readIntent(Order $order): ?PaymentIntent
    {
        try {
            return $this->orderPaymentService->readPaymentIntent($order);
        } catch (Throwable $exception) {
            $this->logger->error('LedgerDirect: open order with an unreadable payment record skipped', [
                'order' => $order->getIncrementId(),
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
