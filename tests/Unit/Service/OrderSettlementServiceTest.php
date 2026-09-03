<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Service;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Model\Settlement\AmountCheck;
use Hardcastle\LedgerDirect\Model\Settlement\SettlementResult;
use Hardcastle\LedgerDirect\Service\OrderSettlementService;
use Magento\Framework\DB\Transaction;
use Magento\Framework\DB\TransactionFactory;
use Magento\Payment\Model\MethodInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Config;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Service\InvoiceService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OrderSettlementServiceTest extends TestCase
{
    /** @var InvoiceService|MockObject */
    private $invoiceService;

    /** @var Transaction|MockObject */
    private $transaction;

    /** @var OrderSender|MockObject */
    private $orderSender;

    /** @var InvoiceSender|MockObject */
    private $invoiceSender;

    /** @var LoggerInterface|MockObject */
    private $logger;

    private OrderSettlementService $service;

    protected function setUp(): void
    {
        $this->invoiceService = $this->createMock(InvoiceService::class);
        $this->transaction = $this->createMock(Transaction::class);
        $this->transaction->method('addObject')->willReturnSelf();
        $transactionFactory = $this->createMock(TransactionFactory::class);
        $transactionFactory->method('create')->willReturn($this->transaction);
        $this->orderSender = $this->createMock(OrderSender::class);
        $this->invoiceSender = $this->createMock(InvoiceSender::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new OrderSettlementService(
            new AmountCheck(),
            $this->invoiceService,
            $transactionFactory,
            $this->orderSender,
            $this->invoiceSender,
            $this->logger
        );
    }

    public function testAFullPaymentIsInvoicedOfflineAndMovesTheOrderToTheSettledStatus(): void
    {
        $order = $this->givenOrder(canInvoice: true, settledStatus: 'processing');
        $invoice = $this->createMock(Invoice::class);
        $invoiceData = [];
        $invoice->method('setData')->willReturnCallback(function ($key, $value) use (&$invoiceData, $invoice) {
            $invoiceData[$key] = $value;

            return $invoice;
        });
        $invoice->expects($this->once())->method('register');
        $this->invoiceService->expects($this->once())->method('prepareInvoice')->with($order)->willReturn($invoice);

        $order->expects($this->once())->method('setData')->with('is_in_process', true)->willReturnSelf();
        $order->expects($this->once())->method('setState')->with(Order::STATE_PROCESSING)->willReturnSelf();
        $order->expects($this->once())->method('setStatus')->with('processing')->willReturnSelf();
        $order->expects($this->once())->method('addCommentToStatusHistory')
            ->with($this->callback(static fn ($c): bool => str_contains((string) $c, 'settled')), $this->anything());
        $this->transaction->expects($this->once())->method('save');
        $this->orderSender->expects($this->once())->method('send')->with($order);
        $this->invoiceSender->expects($this->once())->method('send')->with($invoice);

        $result = $this->service->settle($order, $this->xrpIntent(100.0)->withFulfillment('HASH', 100.0, 'CTID'));

        $this->assertTrue($result->isSettled());
        $this->assertSame('100', $result->getAmountPaid());
        $this->assertSame('100', $result->getAmountRequested());
        $this->assertSame(
            ['requested_capture_case' => Invoice::CAPTURE_OFFLINE, 'transaction_id' => 'HASH'],
            $invoiceData,
            'captured offline, referencing the ledger transaction'
        );
    }

    /**
     * settled_status is merchant configuration; a status that does not belong to the processing
     * state must not be applied blindly.
     */
    public function testAnUnknownSettledStatusFallsBackToTheProcessingDefault(): void
    {
        $order = $this->givenOrder(canInvoice: true, settledStatus: 'nonsense');
        $this->invoiceService->method('prepareInvoice')->willReturn($this->createMock(Invoice::class));
        $order->method('setState')->willReturnSelf();
        $order->expects($this->once())->method('setStatus')->with('processing')->willReturnSelf();

        $this->service->settle($order, $this->xrpIntent(100.0)->withFulfillment('HASH', 100.0));
    }

    public function testAnUnderpaymentKeepsTheOrderPendingWithoutAnInvoice(): void
    {
        $order = $this->givenOrder(canInvoice: true);
        $this->invoiceService->expects($this->never())->method('prepareInvoice');
        $this->orderSender->expects($this->never())->method('send');
        $order->expects($this->once())->method('addCommentToStatusHistory')
            ->with($this->callback(static fn ($c): bool => str_contains((string) $c, '0.84 of 26.75411 XRP')));
        $order->expects($this->once())->method('save');

        $result = $this->service->settle($order, $this->xrpIntent(26.75411)->withFulfillment('HASH', 0.84));

        $this->assertSame(SettlementResult::UNDERPAID, $result->getStatus());
        $this->assertSame('0.84', $result->getAmountPaid());
    }

    /**
     * A payment that arrives after Magento's pending-payment cron cancelled the order must not
     * resurrect it - it is money the merchant has to deal with by hand, so it is logged loudly.
     */
    public function testAPaymentForAnOrderThatCannotBeInvoicedIsRecordedButNotSettled(): void
    {
        $order = $this->givenOrder(canInvoice: false);
        $this->invoiceService->expects($this->never())->method('prepareInvoice');
        $this->logger->expects($this->once())->method('warning');
        $order->expects($this->once())->method('addCommentToStatusHistory')
            ->with($this->callback(static fn ($c): bool => str_contains((string) $c, 'can no longer be paid')));
        $order->expects($this->once())->method('save');

        $result = $this->service->settle($order, $this->xrpIntent(100.0)->withFulfillment('HASH', 100.0));

        $this->assertSame(SettlementResult::NOT_PAYABLE, $result->getStatus());
    }

    /**
     * The payment page and the cron may both settle the same order; the second call must not
     * create a second invoice.
     */
    public function testAnAlreadyPaidOrderIsReportedSettledWithoutASecondInvoice(): void
    {
        $order = $this->givenOrder(canInvoice: false, totalDue: 0.0);
        $this->invoiceService->expects($this->never())->method('prepareInvoice');
        $order->expects($this->never())->method('addCommentToStatusHistory');

        $result = $this->service->settle($order, $this->xrpIntent(100.0)->withFulfillment('HASH', 100.0));

        $this->assertTrue($result->isSettled());
    }

    /**
     * @return Order|MockObject
     */
    private function givenOrder(bool $canInvoice, string $settledStatus = 'processing', float $totalDue = 39.0)
    {
        $method = $this->createMock(MethodInterface::class);
        $method->method('getConfigData')->with('settled_status')->willReturn($settledStatus);
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethodInstance')->willReturn($method);

        $config = $this->createMock(Config::class);
        $config->method('getStateStatuses')->with(Order::STATE_PROCESSING)->willReturn(['processing' => 'Processing']);
        $config->method('getStateDefaultStatus')->with(Order::STATE_PROCESSING)->willReturn('processing');

        $order = $this->createMock(Order::class);
        $order->method('getTotalDue')->willReturn($totalDue);
        $order->method('canInvoice')->willReturn($canInvoice);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getConfig')->willReturn($config);
        $order->method('getIncrementId')->willReturn('000000042');
        $order->method('getStatus')->willReturn('processing');
        $order->method('getEmailSent')->willReturn(null);

        return $order;
    }

    private function xrpIntent(float $requested): PaymentIntent
    {
        return PaymentIntent::quote('xrp-payment', 'XRPL', 'testnet', 'XRP', 'USD', 'XRP/USD', 1.45, $requested, 'rMerchant', 114729);
    }
}
