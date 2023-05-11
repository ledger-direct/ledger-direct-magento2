<?php declare(strict_types=1);

/**
 * Copyright (c) Alexander Busse | Hardcastle Technologies.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Hardcastle\LedgerDirect\Controller\Payment;

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

    public function __construct(
       Session $session,
        RequestInterface $request,
        PageFactory $pageFactory
    ) {
        $this->session = $session;
        $this->request = $request;
        $this->pageFactory = $pageFactory;
    }

    public function execute(): Page
    {
        return $this->pageFactory->create();
    }
}
