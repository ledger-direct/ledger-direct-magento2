<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Service;

use Hardcastle\LedgerDirect\Service\PaymentRedirect;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Checkout\Model\Session\SuccessValidator;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadata;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Helper\Guest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PaymentRedirectTest extends TestCase
{
    /** @var CheckoutSession|MockObject */
    private $checkoutSession;

    /** @var SuccessValidator|MockObject */
    private $successValidator;

    /** @var CustomerSession|MockObject */
    private $customerSession;

    /** @var CookieManagerInterface|MockObject */
    private $cookieManager;

    private PaymentRedirect $redirect;

    protected function setUp(): void
    {
        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturnCallback(
            static fn (string $path, ?array $params = null) => 'https://shop.test/' . $path . ($params ? '?' . http_build_query($params) : '')
        );
        $this->checkoutSession = $this->createMock(CheckoutSession::class);
        $this->successValidator = $this->createMock(SuccessValidator::class);
        $this->customerSession = $this->createMock(CustomerSession::class);
        $this->cookieManager = $this->createMock(CookieManagerInterface::class);
        $metadata = $this->createMock(PublicCookieMetadata::class);
        $metadata->method('setPath')->willReturnSelf();
        $metadata->method('setHttpOnly')->willReturnSelf();
        $metadata->method('setSameSite')->willReturnSelf();
        $metadataFactory = $this->createMock(CookieMetadataFactory::class);
        $metadataFactory->method('createPublicCookieMetadata')->willReturn($metadata);

        $this->redirect = new PaymentRedirect(
            $urlBuilder,
            $this->checkoutSession,
            $this->successValidator,
            $this->customerSession,
            $this->cookieManager,
            $metadataFactory
        );
    }

    /**
     * The customer who kept the page open after the checkout lands on the order confirmation.
     */
    public function testTheCheckoutSessionThatPlacedTheOrderGetsTheSuccessPage(): void
    {
        $this->successValidator->method('isValid')->willReturn(true);
        $this->checkoutSession->method('getData')->with('last_order_id')->willReturn('42');
        $this->cookieManager->expects($this->never())->method('setPublicCookie');

        $this->assertSame('https://shop.test/checkout/onepage/success', $this->redirect->target($this->givenOrder(7)));
    }

    /**
     * The success page sends anyone it does not know to the cart, so a customer coming back
     * later gets the order view instead.
     */
    public function testALoggedInCustomerWithoutTheCheckoutSessionGetsTheirOrderView(): void
    {
        $this->successValidator->method('isValid')->willReturn(false);
        $this->customerSession->method('getCustomerId')->willReturn(7);

        $this->assertSame('https://shop.test/sales/order/view?order_id=42', $this->redirect->target($this->givenOrder(7)));
    }

    /**
     * A guest gets Magento's guest order view, opened by the same cookie Magento's own
     * "Orders and Returns" form sets - the key in the request already proved what it asks.
     */
    public function testAGuestGetsTheGuestOrderViewWithTheCookieSet(): void
    {
        $this->successValidator->method('isValid')->willReturn(false);
        $this->customerSession->method('getCustomerId')->willReturn(null);
        $this->cookieManager->expects($this->once())->method('setPublicCookie')
            ->with(Guest::COOKIE_NAME, base64_encode('protect:100000042'), $this->anything());

        $this->assertSame('https://shop.test/sales/guest/view', $this->redirect->target($this->givenOrder(null)));
    }

    /**
     * @return OrderInterface|MockObject
     */
    private function givenOrder(?int $customerId)
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(42);
        $order->method('getIncrementId')->willReturn('100000042');
        $order->method('getProtectCode')->willReturn('protect');
        $order->method('getCustomerId')->willReturn($customerId);

        return $order;
    }
}
