<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\View\LayoutFactory;
use Magento\Store\Model\ScopeInterface;

class Data extends AbstractHelper
{
    protected LayoutFactory $layoutFactory;

    /**
     * @param Context $context
     * @param LayoutFactory $layoutFactory
     */
    public function __construct(
        Context $context,
        LayoutFactory $layoutFactory
    ) {
        parent::__construct($context);

        $this->layoutFactory = $layoutFactory;
    }


    /**
     * Get store config value
     *
     * @param $field
     * @param null $storeId
     * @return string
     */
    public function getConfigValue($field, $storeId = null): string
    {
        return $this->scopeConfig->getValue(
            $field,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * @return mixed
     */
    public function getIconPhtml(string $icon): string
    {
        $allowedIcons = ['wallet', 'tag', 'copy-paste', 'qr'];
        if (!in_array($icon, $allowedIcons)) {
            //TODO: Throw exception

            return '';
        }

        $layout = $this->layoutFactory->create();
        $iconTemplate = 'Hardcastle_LedgerDirect::' . $icon . 'phtml';
        $blockOption = $layout->createBlock("Hardcastle\LedgerDirect\Block\Extension")->setTemplate($iconTemplate);

        return $blockOption->toHtml();
    }
}
