<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;

class SystemConfig extends AbstractHelper
{
    /**
     * Get store config value
     *
     * @param string $field
     * @param int|string|null $storeId
     * @return string|null
     */
    public function getConfigValue(string $field, $storeId = null): ?string
    {
        return $this->scopeConfig->getValue(
            $field,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Whether payments are configured to settle on the XRPL testnet
     *
     * @return bool
     */
    public function isTest(): bool
    {
        $test = $this->getConfigValue('payment/ledger_direct/use_testnet') ?? true;

        return (bool) $test;
    }

    /**
     * Get the configured XRPL destination account for the active network
     *
     * @return string
     */
    public function getDestinationAccount(): string
    {
        if (!$this->isTest()) {
            return $this->getConfigValue('payment/ledger_direct/xrpl_mainnet_account');
        }

        return $this->getConfigValue('payment/ledger_direct/xrpl_testnet_account');
    }
}
