<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Service;

use Hardcastle\LedgerDirect\Service\OrderAccess;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Key knowledge, not account membership: the order's protect_code opens the payment page and
 * the status endpoint for anyone who has it; the session is the fallback for those who do not.
 */
class OrderAccessTest extends TestCase
{
    private const KEY = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    /** @var OrderRepositoryInterface|MockObject */
    private $orderRepository;

    /** @var CustomerSession|MockObject */
    private $customerSession;

    /** @var CheckoutSession|MockObject */
    private $checkoutSession;

    private OrderAccess $access;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->customerSession = $this->createMock(CustomerSession::class);
        $this->checkoutSession = $this->createMock(CheckoutSession::class);

        $this->access = new OrderAccess($this->orderRepository, $this->customerSession, $this->checkoutSession);
    }

    public function testTheKeyOpensTheOrderWithoutAnySession(): void
    {
        $order = $this->givenOrder();

        $this->assertSame($order, $this->access->authorisedOrder($this->request(['id' => '42', 'key' => self::KEY])));
        $this->assertTrue($this->access->hasValidKey($this->request(['id' => '42', 'key' => self::KEY]), $order));
    }

    public function testAWrongKeyIsRefused(): void
    {
        $order = $this->givenOrder();

        $this->assertNull($this->access->authorisedOrder($this->request(['id' => '42', 'key' => 'wrong'])));
        $this->assertFalse($this->access->hasValidKey($this->request(['id' => '42']), $order));
    }

    /**
     * A missing order and a wrong key look the same from outside.
     */
    public function testAnUnknownOrderIsRefusedTheSameWay(): void
    {
        $this->orderRepository->method('get')->willThrowException(new NoSuchEntityException());

        $this->assertNull($this->access->authorisedOrder($this->request(['id' => '9999', 'key' => self::KEY])));
        $this->assertNull($this->access->authorisedOrder($this->request(['id' => '0', 'key' => self::KEY])));
    }

    public function testAnOrderOfAnotherPaymentMethodIsNotServed(): void
    {
        $this->givenOrder(paymentMethod: 'checkmo');

        $this->assertNull($this->access->authorisedOrder($this->request(['id' => '42', 'key' => self::KEY])));
    }

    public function testTheCustomerTheOrderBelongsToNeedsNoKey(): void
    {
        $order = $this->givenOrder(customerId: 7);
        $this->customerSession->method('getCustomerId')->willReturn(7);

        $this->assertSame($order, $this->access->authorisedOrder($this->request(['id' => '42'])));
    }

    public function testAnotherCustomerIsRefused(): void
    {
        $this->givenOrder(customerId: 7);
        $this->customerSession->method('getCustomerId')->willReturn(8);

        $this->assertNull($this->access->authorisedOrder($this->request(['id' => '42'])));
    }

    /**
     * The guest in the second after the checkout: the JS only has the entity id, and the
     * checkout session still knows the order it just placed.
     */
    public function testTheCheckoutSessionThatPlacedTheOrderNeedsNoKey(): void
    {
        $order = $this->givenOrder(customerId: null);
        $this->checkoutSession->method('getData')->with('last_real_order_id')->willReturn('100000042');

        $this->assertSame($order, $this->access->authorisedOrder($this->request(['id' => '42'])));
        $this->assertFalse($this->access->hasValidKey($this->request(['id' => '42']), $order), 'and is sent on with the key');
    }

    public function testAGuestOrderIsNotOpenToAnyLoggedInCustomer(): void
    {
        $this->givenOrder(customerId: null);
        $this->customerSession->method('getCustomerId')->willReturn(8);
        $this->checkoutSession->method('getData')->willReturn('100000001');

        $this->assertNull($this->access->authorisedOrder($this->request(['id' => '42'])));
    }

    /**
     * @return OrderInterface|MockObject
     */
    private function givenOrder(string $paymentMethod = 'xrp_payment', ?int $customerId = 7)
    {
        $payment = $this->createMock(OrderPaymentInterface::class);
        $payment->method('getMethod')->willReturn($paymentMethod);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(42);
        $order->method('getIncrementId')->willReturn('100000042');
        $order->method('getProtectCode')->willReturn(self::KEY);
        $order->method('getCustomerId')->willReturn($customerId);
        $order->method('getPayment')->willReturn($payment);

        $this->orderRepository->method('get')->with(42)->willReturn($order);

        return $order;
    }

    /**
     * @param array<string, string> $params
     * @return RequestInterface|MockObject
     */
    private function request(array $params)
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $name, $default = null) => $params[$name] ?? $default
        );

        return $request;
    }
}
