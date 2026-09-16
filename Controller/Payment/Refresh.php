<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Controller\Payment;

use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Service\OrderAccess;
use Hardcastle\LedgerDirect\Service\OrderPaymentService;
use InvalidArgumentException;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;

/**
 * A new quote for an expired one - the button under the "quote expired" notice.
 *
 * Only while nothing has arrived: a payment on the tag, short or in the wrong asset, is
 * matched against the quote it was made for, and a refresh would re-quote an order that is
 * already partly paid. The destination account and tag stay the same either way (see
 * OrderPaymentService::prepareOrderPaymentForXrpl()).
 *
 * A POST, so Magento's form-key check applies; the form on the page carries the key.
 */
class Refresh implements HttpPostActionInterface
{
    /**
     * @var RequestInterface
     */
    private RequestInterface $request;

    /**
     * @var RedirectFactory
     */
    private RedirectFactory $redirectFactory;

    /**
     * @var Session
     */
    private Session $customerSession;

    /**
     * @var OrderAccess
     */
    private OrderAccess $orderAccess;

    /**
     * @var OrderPaymentService
     */
    private OrderPaymentService $orderPaymentService;

    /**
     * @var SettlementPolicy
     */
    private SettlementPolicy $settlementPolicy;

    /**
     * @param RequestInterface $request
     * @param RedirectFactory $redirectFactory
     * @param Session $customerSession
     * @param OrderAccess $orderAccess
     * @param OrderPaymentService $orderPaymentService
     * @param SettlementPolicy $settlementPolicy
     */
    public function __construct(
        RequestInterface $request,
        RedirectFactory $redirectFactory,
        Session $customerSession,
        OrderAccess $orderAccess,
        OrderPaymentService $orderPaymentService,
        SettlementPolicy $settlementPolicy
    ) {
        $this->request = $request;
        $this->redirectFactory = $redirectFactory;
        $this->customerSession = $customerSession;
        $this->orderAccess = $orderAccess;
        $this->orderPaymentService = $orderPaymentService;
        $this->settlementPolicy = $settlementPolicy;
    }

    /**
     * Re-quote an expired order and return to its payment page
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $order = $this->orderAccess->authorisedOrder($this->request);

        if ($order === null) {
            return $this->redirectFactory->create()->setPath(
                $this->customerSession->isLoggedIn() ? 'sales/order/history' : 'sales/guest/form'
            );
        }

        $backToPaymentPage = $this->redirectFactory->create()->setPath('ledger-direct/payment/index', [
            OrderAccess::ORDER_ID_PARAMETER => (int) $order->getEntityId(),
            OrderAccess::KEY_PARAMETER => $this->orderAccess->keyOf($order),
        ]);

        if (!$this->orderPaymentService->isAwaitingPayment($order)) {
            return $backToPaymentPage;
        }

        try {
            $intent = $this->orderPaymentService->readPaymentIntent($order);
        } catch (InvalidArgumentException $exception) {
            return $backToPaymentPage;
        }

        if ($intent === null
            || PaymentStatus::fromIntent($intent, $this->settlementPolicy)->state() !== PaymentStatus::EXPIRED
        ) {
            return $backToPaymentPage;
        }

        $this->orderPaymentService->prepareOrderPaymentForXrpl($order, true);

        return $backToPaymentPage;
    }
}
