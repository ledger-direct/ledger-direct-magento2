<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Cron;

use Hardcastle\LedgerDirect\Service\OrderPaymentService;
use Hardcastle\LedgerDirect\Service\OrderSettlementService;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Settles LedgerDirect orders whose payment arrived while nobody was looking at the payment
 * page. Without this, an order is only ever synced when the customer reloads that page - a
 * customer who closes the tab after sending would never see their order confirmed.
 */
class SettlePendingOrders
{
    public const PAYMENT_METHODS = ['xrp_payment', 'xrpl_rlusd_payment', 'xrpl_usdc_payment'];

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
     * Sync and settle every LedgerDirect order still waiting for its payment
     *
     * @return void
     */
    public function execute(): void
    {
        $orders = $this->orderCollectionFactory->create();
        $orders->join(['payment' => 'sales_order_payment'], 'main_table.entity_id = payment.parent_id', [])
            ->addFieldToFilter('payment.method', ['in' => self::PAYMENT_METHODS])
            ->addFieldToFilter('main_table.state', Order::STATE_PENDING_PAYMENT);

        /** @var Order $order */
        foreach ($orders as $order) {
            try {
                $intent = $this->orderPaymentService->syncOrderTransactionWithXrpl($order);

                if ($intent !== null) {
                    $this->settlementService->settle($order, $intent);
                }
            } catch (Throwable $exception) {
                $this->logger->error('LedgerDirect: settling order failed', [
                    'order' => $order->getIncrementId(),
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
    }
}
