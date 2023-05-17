<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;

class Data extends AbstractHelper
{
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

    public function isTest(): bool
    {
        $isTest = true;

        $test = $this->getConfigValue('payment/ledger_direct/use_testnet');

        return (bool) $test;
    }
}
