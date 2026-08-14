<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Model\PaymentMethod;

use Hardcastle\LedgerDirect\Model\PaymentMethod\XrplToken;
use Magento\Payment\Model\InfoInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class XrplTokenTest extends TestCase
{
    /** @var XrplToken|MockObject */
    private $method;

    protected function setUp(): void
    {
        // Disable original constructor from AbstractMethod and mock getInfoInstance when needed
        $this->method = $this->getMockBuilder(XrplToken::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getInfoInstance'])
            ->getMock();
    }

    public function testCapabilitiesFlags()
    {
        $this->assertTrue($this->method->getIsOffline());
        $this->assertFalse($this->method->canAuthorize());
        $this->assertFalse($this->method->canCapture());
        $this->assertFalse($this->method->canRefund());
        $this->assertSame('xrpl_token_payment', $this->method->getCode());
    }

    public function testInitializeSetsPendingPaymentAndDisablesEmail()
    {
        /** @var Order|MockObject $order */
        $order = $this->createMock(Order::class);
        $order->expects($this->once())
            ->method('setCanSendNewEmailFlag')
            ->with(false);

        // Configure order status expectations
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

        // setState should return $order to allow fluent addStatusToHistory
        $order->expects($this->once())
            ->method('setState')
            ->with(Order::STATE_PENDING_PAYMENT)
            ->willReturnSelf();

        $order->expects($this->once())
            ->method('addStatusToHistory')
            ->with(
                'pending',
                $this->stringContains('payment is pending'),
                false
            )
            ->willReturnSelf();

        /** @var InfoInterface|MockObject $paymentInfo */
        $paymentInfo = $this->createMock(InfoInterface::class);
        $paymentInfo->expects($this->once())
            ->method('getOrder')
            ->willReturn($order);

        // Make the payment method return our mocked info instance
        $this->method->expects($this->once())
            ->method('getInfoInstance')
            ->willReturn($paymentInfo);

        $result = $this->method->initialize(null, null);
        $this->assertSame($this->method, $result);
    }
}
