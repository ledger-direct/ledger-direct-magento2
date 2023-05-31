<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\View\LayoutFactory;
use Magento\Store\Model\ScopeInterface;

class Order extends AbstractHelper
{
    /**
     * @param Context $context
     * @param LayoutFactory $layoutFactory
     */
    public function __construct(Context $context,) {

        parent::__construct($context);
    }
}
