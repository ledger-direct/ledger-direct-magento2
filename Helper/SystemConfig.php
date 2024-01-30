<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\View\LayoutFactory;
use Magento\Store\Model\ScopeInterface;

class SystemConfig extends AbstractHelper
{
    /**
     * @param Context $context
     */
    public function __construct(Context $context) {

        parent::__construct($context);
    }


    /**
     * Get store config value
     *
     * @param $field
     * @param null $storeId
     * @return string
     */
    public function getConfigValue($field, $storeId = null): ?string
    {
        return $this->scopeConfig->getValue(
            $field,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isTest(): bool
    {
        $test = $this->getConfigValue('payment/ledger_direct/use_testnet') ?? true;

        return (bool) $test;
    }

    public function getDestinationAccount(): string
    {
        if (!$this->isTest()) {
            return $this->getConfigValue('payment/ledger_direct/xrpl_mainnet_account');
        }

        return $this->getConfigValue('payment/ledger_direct/xrpl_testnet_account');
    }

    public function getTokenName(): string
    {
        if (!$this->isTest()) {
            return $this->getConfigValue('payment/ledger_direct/xrpl_mainnet_custom_token_name');
        }

        return $this->getConfigValue('payment/ledger_direct/xrpl_testnet_custom_token_name');
    }

    public function getTokenIssuer(): string
    {
        if (!$this->isTest()) {
            return $this->getConfigValue('payment/ledger_direct/xrpl_mainnet_custom_token_issuer');
        }

        return $this->getConfigValue('payment/ledger_direct/xrpl_testnet_custom_token_issuer');
    }
}
