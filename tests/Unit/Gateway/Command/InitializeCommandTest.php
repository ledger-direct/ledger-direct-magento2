<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Gateway\Command;

use Hardcastle\LedgerDirect\Gateway\Command\InitializeCommand;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class InitializeCommandTest extends TestCase
{
    public function testExecuteSetsPendingPaymentAndDisablesEmail()
    {
        /** @var Order|MockObject $order */
        $order = $this->createMock(Order::class);
        $order->expects($this->once())
            ->method('setCanSendNewEmailFlag')
            ->with(false);

        $orderConfig = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getStateDefaultStatus'])
            ->getMock();
        $orderConfig->expects($this->once())
            ->method('getStateDefaultStatus')
            ->with(Order::STATE_PENDING_PAYMENT)
            ->willReturn('pending');

        $order->expects($this->once())
            ->method('getConfig')
            ->willReturn($orderConfig);

        $order->expects($this->once())
            ->method('setState')
            ->with(Order::STATE_PENDING_PAYMENT)
            ->willReturnSelf();

        $order->expects($this->once())
            ->method('addStatusToHistory')
            ->with(
                'pending',
                $this->callback(static fn($comment): bool => str_contains((string)$comment, 'payment is pending')),
                false
            )
            ->willReturnSelf();

        /** @var Payment|MockObject $paymentInfo */
        $paymentInfo = $this->createMock(Payment::class);
        $paymentInfo->expects($this->once())
            ->method('getOrder')
            ->willReturn($order);

        /** @var PaymentDataObjectInterface|MockObject $paymentDataObject */
        $paymentDataObject = $this->createMock(PaymentDataObjectInterface::class);
        $paymentDataObject->expects($this->once())
            ->method('getPayment')
            ->willReturn($paymentInfo);

        $command = new InitializeCommand();
        $command->execute(['payment' => $paymentDataObject]);
    }
}
