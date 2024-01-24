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
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

class Index implements HttpGetActionInterface
{
    private Session $session;
    private RequestInterface $request;

    private PageFactory $pageFactory;

    private RedirectFactory $redirectFactory;

    protected OrderPaymentService $orderPaymentService;
    private XrpPaymentServiceInterface $xrpPaymentService;

    public function __construct(
        Session                    $session,
        RequestInterface           $request,
        PageFactory                $pageFactory,
        RedirectFactory            $redirectFactory,
        OrderPaymentService        $orderPaymentService,
        XrpPaymentServiceInterface $xrpPaymentService
    )
    {
        $this->session = $session;
        $this->request = $request;
        $this->pageFactory = $pageFactory;
        $this->redirectFactory = $redirectFactory;
        $this->orderPaymentService = $orderPaymentService;
        $this->xrpPaymentService = $xrpPaymentService;
    }

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
