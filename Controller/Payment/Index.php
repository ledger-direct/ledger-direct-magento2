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

    protected OrderPaymentService $orderPaymentService;
    private XrpPaymentServiceInterface $xrpPaymentService;

    public function __construct(
        Session                    $session,
        RequestInterface           $request,
        PageFactory                $pageFactory,
        OrderPaymentService $orderPaymentService,
        XrpPaymentServiceInterface $xrpPaymentService
    )
    {
        $this->session = $session;
        $this->request = $request;
        $this->pageFactory = $pageFactory;
        $this->orderPaymentService = $orderPaymentService;
        $this->xrpPaymentService = $xrpPaymentService;
    }

    public function execute(): Page
    {
        //TODO: Check Customer Session

        $orderId = (int)$this->request->getParam('id');
        $order = $this->orderPaymentService->getOrder($orderId);

        $tx = $this->orderPaymentService->syncOrderTransactionWithXrpl($order);
        if ($tx) {
            //return new RedirectResponse($request->get('returnUrl'));
        }

        $paymentInfo = $this->xrpPaymentService->getPaymentDetails($orderId);

        $page = $this->pageFactory->create();
        $block = $page->getLayout()->getBlock('ledger-direct.payment.index');
        $block->setData('payment_info', $paymentInfo);

        return $page;
    }
}
