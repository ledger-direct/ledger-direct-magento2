<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Cron;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Cron\SettlePendingOrders;
use Hardcastle\LedgerDirect\Model\Settlement\SettlementResult;
use Hardcastle\LedgerDirect\Service\OrderPaymentService;
use Hardcastle\LedgerDirect\Service\OrderSettlementService;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Collection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SettlePendingOrdersTest extends TestCase
{
    /** @var OrderPaymentService|MockObject */
    private $orderPaymentService;

    /** @var OrderSettlementService|MockObject */
    private $settlementService;

    /** @var LoggerInterface|MockObject */
    private $logger;

    private SettlePendingOrders $cron;

    /** @var Order[] */
    private array $pendingOrders = [];

    protected function setUp(): void
    {
        $this->orderPaymentService = $this->createMock(OrderPaymentService::class);
        $this->settlementService = $this->createMock(OrderSettlementService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $collection = $this->createMock(Collection::class);
        $collection->method('join')->willReturnSelf();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturnCallback(fn () => new \ArrayIterator($this->pendingOrders));
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $this->cron = new SettlePendingOrders(
            $collectionFactory,
            $this->orderPaymentService,
            $this->settlementService,
            $this->logger
        );
    }

    public function testSettlesEveryPendingOrderWhosePaymentArrived(): void
    {
        $paid = $this->createMock(Order::class);
        $unpaid = $this->createMock(Order::class);
        $this->pendingOrders = [$paid, $unpaid];
        $intent = PaymentIntent::quote('xrp-payment', 'XRPL', 'testnet', 'XRP', 'USD', 'XRP/USD', 1.0, 1.0, 'r', 1)
            ->withFulfillment('HASH', 1.0);

        $this->orderPaymentService->method('syncOrderTransactionWithXrpl')
            ->willReturnCallback(fn (Order $order) => $order === $paid ? $intent : null);
        $this->settlementService->expects($this->once())->method('settle')->with($paid, $intent)
            ->willReturn(new SettlementResult(SettlementResult::SETTLED, '1', '1'));

        $this->cron->execute();
    }

    /**
     * One broken order must not stop the others from being settled.
     */
    public function testAFailingOrderIsLoggedAndTheRestContinues(): void
    {
        $broken = $this->createMock(Order::class);
        $fine = $this->createMock(Order::class);
        $this->pendingOrders = [$broken, $fine];

        $this->orderPaymentService->method('syncOrderTransactionWithXrpl')
            ->willReturnCallback(function (Order $order) use ($broken) {
                if ($order === $broken) {
                    throw new \RuntimeException('boom');
                }

                return null;
            });
        $this->logger->expects($this->once())->method('error');

        $this->cron->execute();
    }
}
