<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Cron;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Cron\SettlePendingOrders;
use Hardcastle\LedgerDirect\Model\Settlement\SettlementResult;
use Hardcastle\LedgerDirect\Service\OrderPaymentService;
use Hardcastle\LedgerDirect\Service\OrderSettlementService;
use InvalidArgumentException;
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

    /** @var array<int, PaymentIntent|null|\Throwable> keyed by spl_object_id of the order */
    private array $intents = [];

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

        $this->orderPaymentService->method('readPaymentIntent')->willReturnCallback(function (Order $order) {
            $intent = $this->intents[spl_object_id($order)] ?? null;
            if ($intent instanceof \Throwable) {
                throw $intent;
            }

            return $intent;
        });

        $this->cron = new SettlePendingOrders(
            $collectionFactory,
            $this->orderPaymentService,
            $this->settlementService,
            $this->logger
        );
    }

    /**
     * Two orders on one receiving account cost one node request, not two; a third on the
     * other network costs a second. Every order is then matched against the local table.
     */
    public function testTheLedgerIsSyncedOncePerReceivingAccountAndNetwork(): void
    {
        $first = $this->givenPendingOrder($this->intent('rMerchant', 'testnet'));
        $second = $this->givenPendingOrder($this->intent('rMerchant', 'testnet'));
        $third = $this->givenPendingOrder($this->intent('rMerchant', 'mainnet'));

        $synced = [];
        $this->orderPaymentService->expects($this->exactly(2))->method('syncLedger')
            ->willReturnCallback(function (string $account, string $network, bool $throttled) use (&$synced): void {
                $synced[] = [$account, $network, $throttled];
            });
        $matched = [];
        $this->orderPaymentService->expects($this->exactly(3))->method('matchOrder')
            ->willReturnCallback(function (Order $order) use (&$matched) {
                $matched[] = $order;

                return null;
            });
        $this->settlementService->expects($this->never())->method('settle');

        $this->cron->execute();

        $this->assertSame([['rMerchant', 'testnet', false], ['rMerchant', 'mainnet', false]], $synced, 'unthrottled, once per pair');
        $this->assertSame([$first, $second, $third], $matched);
    }

    public function testSettlesEveryPendingOrderWhosePaymentArrived(): void
    {
        $paid = $this->givenPendingOrder($this->intent());
        $unpaid = $this->givenPendingOrder($this->intent());
        $fulfilled = $this->intent()->withFulfillment('HASH', 1.0);

        $this->orderPaymentService->method('matchOrder')
            ->willReturnCallback(fn (Order $order) => $order === $paid ? $fulfilled : null);
        $this->settlementService->expects($this->once())->method('settle')->with($paid, $fulfilled)
            ->willReturn(new SettlementResult(SettlementResult::SETTLED, '1', '1'));
        $this->logger->expects($this->once())->method('info')
            ->with($this->anything(), ['accounts_synced' => 1, 'checked' => 2, 'settled' => 1]);

        $this->cron->execute();
    }

    /**
     * An order from before the core retrofit ("Missing schema_version"), or one never quoted,
     * is skipped with a log line and must not stop the run.
     */
    public function testAnUnreadableOrNeverQuotedOrderIsSkipped(): void
    {
        $this->givenPendingOrder(new InvalidArgumentException('Missing schema_version'));
        $this->givenPendingOrder(null);
        $fine = $this->givenPendingOrder($this->intent());

        $this->logger->expects($this->once())->method('error');
        $this->orderPaymentService->expects($this->once())->method('syncLedger');
        $this->orderPaymentService->expects($this->once())->method('matchOrder')->with($fine)->willReturn(null);

        $this->cron->execute();
    }

    /**
     * One broken order must not stop the others from being settled.
     */
    public function testAFailingOrderIsLoggedAndTheRestContinues(): void
    {
        $broken = $this->givenPendingOrder($this->intent());
        $fine = $this->givenPendingOrder($this->intent());

        $this->orderPaymentService->method('matchOrder')
            ->willReturnCallback(function (Order $order) use ($broken) {
                if ($order === $broken) {
                    throw new \RuntimeException('boom');
                }

                return null;
            });
        $this->logger->expects($this->once())->method('error');

        $this->cron->execute();

        $this->assertNotNull($fine);
    }

    /**
     * @param PaymentIntent|\Throwable|null $intent what readPaymentIntent() returns or throws
     */
    private function givenPendingOrder(PaymentIntent|\Throwable|null $intent): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn((string) (100 + count($this->pendingOrders)));
        $this->pendingOrders[] = $order;
        $this->intents[spl_object_id($order)] = $intent;

        return $order;
    }

    private function intent(string $account = 'rMerchant', string $network = 'testnet'): PaymentIntent
    {
        return PaymentIntent::quote('xrp-payment', 'XRPL', $network, 'XRP', 'USD', 'XRP/USD', 1.0, 1.0, $account, 1);
    }
}
