<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;

class SystemConfig extends AbstractHelper
{
    public const DEFAULT_QUOTE_EXPIRY_SECONDS = 300;

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
     * The XRPL network payments settle on, in the core's vocabulary
     *
     * @return string 'mainnet' | 'testnet'
     */
    public function getNetwork(): string
    {
        return $this->isTest() ? 'testnet' : 'mainnet';
    }

    /**
     * Get the configured XRPL destination account for the active network
     *
     * @return string
     */
    public function getDestinationAccount(): string
    {
        if (!$this->isTest()) {
            return (string) $this->getConfigValue('payment/ledger_direct/xrpl_mainnet_account');
        }

        return (string) $this->getConfigValue('payment/ledger_direct/xrpl_testnet_account');
    }

    /**
     * Whether the given payment method is switched on in the admin configuration
     *
     * @param string $paymentMethodCode e.g. xrp_payment
     * @return bool
     */
    public function isPaymentMethodActive(string $paymentMethodCode): bool
    {
        return $this->scopeConfig->isSetFlag(
            'payment/' . $paymentMethodCode . '/active',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * How long a price quote handed to the customer stays valid, in seconds
     *
     * @return int
     */
    public function getQuoteExpirySeconds(): int
    {
        $value = $this->getConfigValue('payment/ledger_direct/quote_expiry');

        return is_numeric($value) && (int) $value > 0 ? (int) $value : self::DEFAULT_QUOTE_EXPIRY_SECONDS;
    }

    /**
     * Which logo the payment page shows: shop, custom or none
     *
     * @return string
     */
    public function getPaymentPageLogoMode(): string
    {
        $mode = (string) $this->getConfigValue('payment/ledger_direct/page_logo_mode');

        return in_array($mode, \Hardcastle\LedgerDirect\Model\Config\Source\LogoMode::MODES, true) ? $mode : 'shop';
    }

    /**
     * The uploaded logo's file name below media/ledger_direct/logo, or an empty string
     *
     * @return string
     */
    public function getPaymentPageLogoFile(): string
    {
        return trim((string) $this->getConfigValue('payment/ledger_direct/page_logo'));
    }

    /**
     * The accent colour as stored; the core's AccentColor decides whether the page uses it
     *
     * @return string
     */
    public function getPaymentPageAccentColor(): string
    {
        return trim((string) $this->getConfigValue('payment/ledger_direct/page_accent'));
    }

    /**
     * Xaman's public API key; empty means the "open in wallet app" button is not offered
     *
     * @return string
     */
    public function getXamanApiKey(): string
    {
        return trim((string) $this->getConfigValue('payment/ledger_direct/xaman_api_key'));
    }

    /**
     * The WalletConnect project id; empty means it is not offered
     *
     * @return string
     */
    public function getWalletConnectProjectId(): string
    {
        return trim((string) $this->getConfigValue('payment/ledger_direct/walletconnect_project_id'));
    }
}
