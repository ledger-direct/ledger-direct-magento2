<?php declare(strict_types=1);
/**
 * Copyright (c) Alexander Busse | Hardcastle Technologies.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Hardcastle\LedgerDirect\Controller\Payment;

use Hardcastle\LedgerDirect\Api\XrpPaymentServiceInterface;
use Hardcastle\LedgerDirect\Model\Settlement\SettlementResult;
use Hardcastle\LedgerDirect\Service\OrderPaymentService;
use Hardcastle\LedgerDirect\Service\OrderSettlementService;
use Magento\Framework\Message\ManagerInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\App\RequestInterface;

class Index implements HttpGetActionInterface
{
    /**
     * @var Session
     */
    private Session $session;

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
     * @var OrderPaymentService
     */
    protected OrderPaymentService $orderPaymentService;

    /**
     * @var XrpPaymentServiceInterface
     */
    private XrpPaymentServiceInterface $xrpPaymentService;

    /**
     * @var OrderSettlementService
     */
    private OrderSettlementService $settlementService;

    /**
     * @var ManagerInterface
     */
    private ManagerInterface $messageManager;

    /**
     * @param Session $session
     * @param RequestInterface $request
     * @param PageFactory $pageFactory
     * @param RedirectFactory $redirectFactory
     * @param OrderPaymentService $orderPaymentService
     * @param XrpPaymentServiceInterface $xrpPaymentService
     * @param OrderSettlementService $settlementService
     * @param ManagerInterface $messageManager
     */
    public function __construct(
        Session                    $session,
        RequestInterface           $request,
        PageFactory                $pageFactory,
        RedirectFactory            $redirectFactory,
        OrderPaymentService        $orderPaymentService,
        XrpPaymentServiceInterface $xrpPaymentService,
        OrderSettlementService     $settlementService,
        ManagerInterface           $messageManager
    ) {
        $this->session = $session;
        $this->request = $request;
        $this->pageFactory = $pageFactory;
        $this->redirectFactory = $redirectFactory;
        $this->orderPaymentService = $orderPaymentService;
        $this->xrpPaymentService = $xrpPaymentService;
        $this->settlementService = $settlementService;
        $this->messageManager = $messageManager;
    }

    /**
     * Sync the order's XRPL payment status and render the payment page, or redirect once settled
     *
     * @return Page|Redirect
     */
    public function execute(): Page|Redirect
    {
        if (!$this->session->isLoggedIn()) {
            $redirect = $this->redirectFactory->create();
            return $redirect->setPath('customer/account/login');
        }

        $orderId = (int)$this->request->getParam('id');
        $order = $this->orderPaymentService->getOrderById($orderId);

        if ($order->getCustomerId() !== $this->session->getCustomerId()) {
            $redirect = $this->redirectFactory->create();
            return $redirect->setPath('customer/account/');
        }

        $paymentMethod = $order->getPayment()->getMethod();
        $supportedMethods = ['xrp_payment', 'xrpl_rlusd_payment', 'xrpl_usdc_payment'];
        if (!in_array($paymentMethod, $supportedMethods, true)) {
            $redirect = $this->redirectFactory->create();
            return $redirect->setPath('customer/account/');
        }

        $fulfilledIntent = $this->orderPaymentService->syncOrderTransactionWithXrpl($order);
        if ($fulfilledIntent !== null) {
            $settlement = $this->settlementService->settle($order, $fulfilledIntent);

            if ($settlement->isSettled()) {
                return $this->redirectFactory->create()->setPath('checkout/onepage/success');
            }

            if ($settlement->getStatus() === SettlementResult::NOT_PAYABLE) {
                $this->messageManager->addErrorMessage(
                    __('A payment for this order arrived, but the order can no longer be paid. Please contact us.')
                );

                return $this->redirectFactory->create()->setPath('sales/order/history');
            }

            // Underpaid: fall through and show the page with what arrived so far.
        }

        $paymentInfo = $this->xrpPaymentService->getPaymentDetailsByOrderId($orderId);

        $page = $this->pageFactory->create();
        $block = $page->getLayout()->getBlock('ledger-direct.payment.index');
        $block->setData('payment_info', $paymentInfo);

        return $page;
    }
}
