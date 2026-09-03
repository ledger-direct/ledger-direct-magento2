<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Gateway\Command;

use Hardcastle\LedgerDirect\Gateway\Command\InitializeCommand;
use Magento\Framework\DataObject;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Magento\Sales\Model\Order\Payment::place() takes state, status and the notification flag from
 * the $stateObject it passes to initialize(), and overwrites anything the command sets on the
 * order itself - so the contract under test is what ends up in that object.
 */
class InitializeCommandTest extends TestCase
{
    public function testExecuteReportsPendingPaymentThroughTheStateObject(): void
    {
        $orderConfig = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getStateDefaultStatus'])
            ->getMock();
        $orderConfig->method('getStateDefaultStatus')
            ->with(Order::STATE_PENDING_PAYMENT)
            ->willReturn('pending_payment');

        /** @var Order|MockObject $order */
        $order = $this->createMock(Order::class);
        $order->method('getConfig')->willReturn($orderConfig);
        $order->expects($this->never())->method('setState');
        $order->expects($this->once())->method('setCanSendNewEmailFlag')->with(false);
        $order->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with(
                $this->callback(static fn($comment): bool => str_contains((string) $comment, 'payment is pending')),
                'pending_payment'
            );

        /** @var Payment|MockObject $paymentInfo */
        $paymentInfo = $this->createMock(Payment::class);
        $paymentInfo->method('getOrder')->willReturn($order);

        /** @var PaymentDataObjectInterface|MockObject $paymentDataObject */
        $paymentDataObject = $this->createMock(PaymentDataObjectInterface::class);
        $paymentDataObject->method('getPayment')->willReturn($paymentInfo);

        $stateObject = new DataObject();

        (new InitializeCommand())->execute([
            'payment' => $paymentDataObject,
            'paymentAction' => 'order',
            'stateObject' => $stateObject,
        ]);

        $this->assertSame(Order::STATE_PENDING_PAYMENT, $stateObject->getData('state'));
        $this->assertSame('pending_payment', $stateObject->getData('status'));
        $this->assertFalse($stateObject->getData('is_notified'));
    }
}
