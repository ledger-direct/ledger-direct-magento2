<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Port;

use Hardcastle\LedgerDirect\Core\Port\ConfigProviderInterface;
use Hardcastle\LedgerDirect\Helper\SystemConfig;
use Hardcastle\LedgerDirect\Model\PaymentMethods;

/**
 * Platform side of the core's {@see ConfigProviderInterface}: a thin facade over the
 * module's SystemConfig helper, which is where Magento's store configuration is read.
 *
 * Chain-generic: the chain names the configuration group the network and accounts are read
 * from, and the asset names the payment method whose "Enabled" switch decides. A chain the
 * module carries no payment methods for is answered with its testnet, no account and no asset,
 * never with another chain's settings.
 */
class MagentoConfigProvider implements ConfigProviderInterface
{
    /**
     * @var SystemConfig
     */
    private SystemConfig $systemConfig;

    /**
     * @var PaymentMethods
     */
    private PaymentMethods $paymentMethods;

    /**
     * @param SystemConfig $systemConfig
     * @param PaymentMethods $paymentMethods
     */
    public function __construct(SystemConfig $systemConfig, PaymentMethods $paymentMethods)
    {
        $this->systemConfig = $systemConfig;
        $this->paymentMethods = $paymentMethods;
    }

    /**
     * @inheritdoc
     */
    public function getNetwork(string $chain): string
    {
        return $this->paymentMethods->supportsChain($chain) ? $this->systemConfig->getNetwork($chain) : 'testnet';
    }

    /**
     * @inheritdoc
     */
    public function getDestinationAccount(string $chain): string
    {
        return $this->paymentMethods->supportsChain($chain) ? $this->systemConfig->getDestinationAccount($chain) : '';
    }

    /**
     * @inheritdoc
     *
     * There is no separate per-asset toggle: the payment method *is* the asset here, so switching
     * a method off in the admin also stops the core from quoting in that asset.
     */
    public function isAssetEnabled(string $chain, string $baseAsset): bool
    {
        $paymentMethod = $this->paymentMethods->codeFor($chain, $baseAsset);
        return $paymentMethod !== null && $this->systemConfig->isPaymentMethodActive($paymentMethod);
    }

    /**
     * @inheritdoc
     */
    public function getQuoteExpirySeconds(): int
    {
        return $this->systemConfig->getQuoteExpirySeconds();
    }
}
