<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Block\Payment\Info;

use Magento\Framework\View\Element\Template\Context;
use Magento\Payment\Block\ConfigurableInfo;
use Magento\Payment\Gateway\ConfigInterface;

class Checkout extends ConfigurableInfo
{
    protected $_template = 'Magento_Payment::info/default.phtml';

    public function __construct(
        Context $context,
        ConfigInterface $config,
        array $data = []
    ) {
        parent::__construct($context, $config, $data);
    }
}
