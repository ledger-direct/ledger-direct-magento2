<?php declare(strict_types=1);
/**
 * Copyright (c) Alexander Busse | Hardcastle Technologies.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Hardcastle\LedgerDirect\Controller\Payment;

use Hardcastle\LedgerDirect\Api\XrpPaymentServiceInterface;
use Hardcastle\LedgerDirect\Service\OrderPaymentService;
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
     * @param Session $session
     * @param RequestInterface $request
     * @param PageFactory $pageFactory
     * @param RedirectFactory $redirectFactory
     * @param OrderPaymentService $orderPaymentService
     * @param XrpPaymentServiceInterface $xrpPaymentService
     */
    public function __construct(
        Session                    $session,
        RequestInterface           $request,
        PageFactory                $pageFactory,
        RedirectFactory            $redirectFactory,
        OrderPaymentService        $orderPaymentService,
        XrpPaymentServiceInterface $xrpPaymentService
    ) {
        $this->session = $session;
        $this->request = $request;
        $this->pageFactory = $pageFactory;
        $this->redirectFactory = $redirectFactory;
        $this->orderPaymentService = $orderPaymentService;
        $this->xrpPaymentService = $xrpPaymentService;
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

        $tx = $this->orderPaymentService->syncOrderTransactionWithXrpl($order);
        if ($tx) {

            // Check if amount is correct!

            $redirect = $this->redirectFactory->create();

            // Order status!
            return $redirect->setPath('checkout/onepage/success');
        }

        $paymentInfo = $this->xrpPaymentService->getPaymentDetailsByOrderId($orderId);

        $page = $this->pageFactory->create();
        $block = $page->getLayout()->getBlock('ledger-direct.payment.index');
        $block->setData('payment_info', $paymentInfo);

        return $page;
    }
}
