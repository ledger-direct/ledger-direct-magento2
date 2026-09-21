<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Checkout\Model\Session\SuccessValidator;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Helper\Guest;

/**
 * Where the payment page sends the customer once the order no longer waits for its payment -
 * the one field of the status payload only the platform can build (INVARIANTS.md, "Payment
 * status": `redirect` is the adapter's).
 *
 * Preferably Magento's own order confirmation, checkout/onepage/success. That page is bound
 * to the checkout session, though, and sends anyone it does not know to the cart: it works
 * for the customer who kept the page open after the checkout, not for one who comes back an
 * hour later from another window. Then the order view: the account's for a logged-in
 * customer, the guest view for everybody else. Magento opens the guest view through the
 * same cookie its own "Orders and Returns" form sets, and the key in the request has already
 * proven what that form asks for.
 */
class PaymentRedirect
{
    /**
     * @var UrlInterface
     */
    private UrlInterface $urlBuilder;

    /**
     * @var CheckoutSession
     */
    private CheckoutSession $checkoutSession;

    /**
     * @var SuccessValidator
     */
    private SuccessValidator $successValidator;

    /**
     * @var CustomerSession
     */
    private CustomerSession $customerSession;

    /**
     * @var CookieManagerInterface
     */
    private CookieManagerInterface $cookieManager;

    /**
     * @var CookieMetadataFactory
     */
    private CookieMetadataFactory $cookieMetadataFactory;

    /**
     * @param UrlInterface $urlBuilder
     * @param CheckoutSession $checkoutSession
     * @param SuccessValidator $successValidator
     * @param CustomerSession $customerSession
     * @param CookieManagerInterface $cookieManager
     * @param CookieMetadataFactory $cookieMetadataFactory
     */
    public function __construct(
        UrlInterface $urlBuilder,
        CheckoutSession $checkoutSession,
        SuccessValidator $successValidator,
        CustomerSession $customerSession,
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory $cookieMetadataFactory
    ) {
        $this->urlBuilder = $urlBuilder;
        $this->checkoutSession = $checkoutSession;
        $this->successValidator = $successValidator;
        $this->customerSession = $customerSession;
        $this->cookieManager = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
    }

    /**
     * The URL the customer is sent to for this order
     *
     * @param OrderInterface $order
     * @return string
     */
    public function target(OrderInterface $order): string
    {
        if ($this->successValidator->isValid()
            && (int) $this->checkoutSession->getData('last_order_id') === (int) $order->getEntityId()
        ) {
            return $this->urlBuilder->getUrl('checkout/onepage/success');
        }

        $customerId = $this->customerSession->getCustomerId();

        if ($customerId !== null && $order->getCustomerId() !== null
            && (int) $customerId === (int) $order->getCustomerId()
        ) {
            return $this->urlBuilder->getUrl('sales/order/view', ['order_id' => (int) $order->getEntityId()]);
        }

        $this->setGuestViewCookie($order);

        return $this->urlBuilder->getUrl('sales/guest/view');
    }

    /**
     * The cookie Magento's guest order view reads, as Sales\Helper\Guest sets it
     *
     * @param OrderInterface $order
     * @return void
     */
    private function setGuestViewCookie(OrderInterface $order): void
    {
        $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
            ->setPath(Guest::COOKIE_PATH)
            ->setHttpOnly(true)
            ->setSameSite('Lax');

        $this->cookieManager->setPublicCookie(
            Guest::COOKIE_NAME,
            base64_encode($order->getProtectCode() . ':' . $order->getIncrementId()),
            $metadata
        );
    }
}
