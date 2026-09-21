<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Who may see an order's payment page and ask for its payment status.
 *
 * Key knowledge, not account membership: the payment-status contract (INVARIANTS.md,
 * "Payment status") wants a per-order secret and no login, because guest checkout is the
 * rule in crypto payments. Magento's own secret for that is the order's protect_code - the
 * code behind the guest order view - so the same key opens the payment page an hour later
 * in a fresh browser, guest or not.
 *
 * Without a key, the customer in the session whose id the order carries is let in (the way
 * in from the account's order history), and so is the checkout session that has just
 * placed the order: the checkout only hands the payment page the order's entity id. The
 * page then sends the browser once more to itself, with the key, so the address bar
 * carries the URL that keeps working.
 *
 * Nothing here reveals whether an order exists: a wrong key, a wrong id and an order of
 * another payment method all come back as null, and the callers answer them the same way.
 */
class OrderAccess
{
    public const ORDER_ID_PARAMETER = 'id';

    public const KEY_PARAMETER = 'key';

    /**
     * @var OrderRepositoryInterface
     */
    private OrderRepositoryInterface $orderRepository;

    /**
     * @var CustomerSession
     */
    private CustomerSession $customerSession;

    /**
     * @var CheckoutSession
     */
    private CheckoutSession $checkoutSession;

    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param CustomerSession $customerSession
     * @param CheckoutSession $checkoutSession
     */
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        CustomerSession $customerSession,
        CheckoutSession $checkoutSession
    ) {
        $this->orderRepository = $orderRepository;
        $this->customerSession = $customerSession;
        $this->checkoutSession = $checkoutSession;
    }

    /**
     * The LedgerDirect order the request names, or null when the request may not see it
     *
     * @param RequestInterface $request
     * @return OrderInterface|null
     */
    public function authorisedOrder(RequestInterface $request): ?OrderInterface
    {
        $orderId = (int) $request->getParam(self::ORDER_ID_PARAMETER);

        if ($orderId <= 0) {
            return null;
        }

        try {
            $order = $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException $exception) {
            return null;
        }

        $payment = $order->getPayment();

        if ($payment === null || !in_array($payment->getMethod(), OrderPaymentService::PAYMENT_METHODS, true)) {
            return null;
        }

        if ($this->hasValidKey($request, $order)) {
            return $order;
        }

        $customerId = $this->customerSession->getCustomerId();

        if ($customerId !== null && $order->getCustomerId() !== null
            && (int) $customerId === (int) $order->getCustomerId()
        ) {
            return $order;
        }

        $lastRealOrderId = (string) $this->checkoutSession->getData('last_real_order_id');

        if ($lastRealOrderId !== '' && $lastRealOrderId === (string) $order->getIncrementId()) {
            return $order;
        }

        return null;
    }

    /**
     * Whether the request carries the order's key
     *
     * @param RequestInterface $request
     * @param OrderInterface $order
     * @return bool
     */
    public function hasValidKey(RequestInterface $request, OrderInterface $order): bool
    {
        $key = (string) $request->getParam(self::KEY_PARAMETER, '');
        $expected = $this->keyOf($order);

        return $key !== '' && $expected !== '' && hash_equals($expected, $key);
    }

    /**
     * The order's key: Magento's protect_code, 32 hex characters of a salted SHA-256
     *
     * @param OrderInterface $order
     * @return string
     */
    public function keyOf(OrderInterface $order): string
    {
        return (string) $order->getProtectCode();
    }
}
