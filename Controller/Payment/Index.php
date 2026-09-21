<?php declare(strict_types=1);
/**
 * Copyright (c) Alexander Busse | Hardcastle Technologies.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Hardcastle\LedgerDirect\Controller\Payment;

use Hardcastle\LedgerDirect\Api\XrpPaymentServiceInterface;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Service\OrderAccess;
use Hardcastle\LedgerDirect\Service\OrderPaymentService;
use Hardcastle\LedgerDirect\Service\OrderSettlementService;
use Hardcastle\LedgerDirect\Service\PaymentRedirect;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * The payment page: amount, receiving account and destination tag, in one of the five
 * states of the payment-status contract.
 *
 * No login required: the order's key (or the session the order belongs to) authorises the
 * request, see OrderAccess. A guest who has just placed the order arrives with the entity
 * id only and is sent once more to this page with the key, so the address bar carries the
 * URL that still works in an hour, in another browser.
 */
class Index implements HttpGetActionInterface
{
    /**
     * @var Session
     */
    private Session $customerSession;

    /**
     * @var RequestInterface
     */
    private RequestInterface $request;

    /**
     * @var PageFactory
     */
    private PageFactory $pageFactory;

    /**
     * @var RedirectFactory
     */
    private RedirectFactory $redirectFactory;

    /**
     * @var UrlInterface
     */
    private UrlInterface $urlBuilder;

    /**
     * @var FormKey
     */
    private FormKey $formKey;

    /**
     * @var OrderAccess
     */
    private OrderAccess $orderAccess;

    /**
     * @var OrderPaymentService
     */
    private OrderPaymentService $orderPaymentService;

    /**
     * @var XrpPaymentServiceInterface
     */
    private XrpPaymentServiceInterface $xrpPaymentService;

    /**
     * @var OrderSettlementService
     */
    private OrderSettlementService $settlementService;

    /**
     * @var SettlementPolicy
     */
    private SettlementPolicy $settlementPolicy;

    /**
     * @var PaymentRedirect
     */
    private PaymentRedirect $paymentRedirect;

    /**
     * @param Session $customerSession
     * @param RequestInterface $request
     * @param PageFactory $pageFactory
     * @param RedirectFactory $redirectFactory
     * @param UrlInterface $urlBuilder
     * @param FormKey $formKey
     * @param OrderAccess $orderAccess
     * @param OrderPaymentService $orderPaymentService
     * @param XrpPaymentServiceInterface $xrpPaymentService
     * @param OrderSettlementService $settlementService
     * @param SettlementPolicy $settlementPolicy
     * @param PaymentRedirect $paymentRedirect
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Session $customerSession,
        RequestInterface $request,
        PageFactory $pageFactory,
        RedirectFactory $redirectFactory,
        UrlInterface $urlBuilder,
        FormKey $formKey,
        OrderAccess $orderAccess,
        OrderPaymentService $orderPaymentService,
        XrpPaymentServiceInterface $xrpPaymentService,
        OrderSettlementService $settlementService,
        SettlementPolicy $settlementPolicy,
        PaymentRedirect $paymentRedirect
    ) {
        $this->customerSession = $customerSession;
        $this->request = $request;
        $this->pageFactory = $pageFactory;
        $this->redirectFactory = $redirectFactory;
        $this->urlBuilder = $urlBuilder;
        $this->formKey = $formKey;
        $this->orderAccess = $orderAccess;
        $this->orderPaymentService = $orderPaymentService;
        $this->xrpPaymentService = $xrpPaymentService;
        $this->settlementService = $settlementService;
        $this->settlementPolicy = $settlementPolicy;
        $this->paymentRedirect = $paymentRedirect;
    }

    /**
     * Sync the order's XRPL payment and render the payment page, or send the customer on once it no longer waits
     *
     * @return Page|Redirect
     */
    public function execute(): Page|Redirect
    {
        $order = $this->orderAccess->authorisedOrder($this->request);

        if ($order === null) {
            // Same answer for "no such order" and "not yours": neither is told apart.
            return $this->redirectFactory->create()->setPath(
                $this->customerSession->isLoggedIn() ? 'sales/order/history' : 'sales/guest/form'
            );
        }

        $orderId = (int) $order->getEntityId();
        $key = $this->orderAccess->keyOf($order);

        if (!$this->orderAccess->hasValidKey($this->request, $order)) {
            return $this->redirectFactory->create()->setPath('ledger-direct/payment/index', [
                OrderAccess::ORDER_ID_PARAMETER => $orderId,
                OrderAccess::KEY_PARAMETER => $key,
            ]);
        }

        /*
         * Synced on render so the first page after the checkout shows the current state;
         * throttled so a reload costs no node request. A hit settles the order right here,
         * as the status endpoint does. Something arriving on the tag is not the same as the
         * order being paid: a short payment, or one in the wrong asset, keeps the customer
         * here and the page tells them what is missing. Only an order that no longer waits -
         * settled, or closed by the merchant - leaves the page.
         */
        if ($this->orderPaymentService->isAwaitingPayment($order)) {
            $fulfilledIntent = $this->orderPaymentService->syncOrderTransactionWithXrpl($order, true);

            if ($fulfilledIntent !== null) {
                $this->settlementService->settle($order, $fulfilledIntent);
            }
        }

        if (!$this->orderPaymentService->isAwaitingPaymentById($orderId)) {
            return $this->redirectFactory->create()->setUrl($this->paymentRedirect->target($order));
        }

        $paymentInfo = $this->xrpPaymentService->getPaymentDetails($order);
        $intent = $this->orderPaymentService->readPaymentIntent($order);
        $status = PaymentStatus::fromIntent($intent, $this->settlementPolicy);

        $page = $this->pageFactory->create();
        $block = $page->getLayout()->getBlock('ledger-direct.payment.index');
        $block->setData('payment_info', $paymentInfo);
        $block->setData('payment_status', $status);
        $block->setData('has_expiry', $intent->expiry !== null);
        $block->setData('access_key', $key);
        $block->setData('form_key', $this->formKey->getFormKey());
        $block->setData('poll_url', $this->urlBuilder->getUrl('ledger-direct/payment/status', [
            OrderAccess::ORDER_ID_PARAMETER => $orderId,
            OrderAccess::KEY_PARAMETER => $key,
        ]));
        $block->setData('refresh_url', $this->urlBuilder->getUrl('ledger-direct/payment/refresh'));

        return $page;
    }
}
