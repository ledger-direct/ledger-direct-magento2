<?php declare(strict_types=1);

/**
 * Copyright (c) Alexander Busse | Hardcastle Technologies.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Hardcastle\LedgerDirect\Controller\Payment;

use Hardcastle\LedgerDirect\Api\XrpPaymentServiceInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\App\RequestInterface;

class Index implements HttpGetActionInterface
{
    private Session $session;
    private RequestInterface $request;
    private PageFactory $pageFactory;

    private XrpPaymentServiceInterface $xrpPaymentService;

    public function __construct(
       Session $session,
        RequestInterface $request,
        PageFactory $pageFactory,
       XrpPaymentServiceInterface $xrpPaymentService
    ) {
        $this->session = $session;
        $this->request = $request;
        $this->pageFactory = $pageFactory;
        $this->xrpPaymentService = $xrpPaymentService;
    }

    public function execute(): Page
    {
        $orderId = (int)$this->request->getParam('id');
        $paymentInfo = $this->xrpPaymentService->getPaymentDetails($orderId);

        $page = $this->pageFactory->create();
        $block = $page->getLayout()->getBlock('ledger-direct.payment.index');
        $block->setData('payment_info', $paymentInfo);

        return $page;
    }
}
