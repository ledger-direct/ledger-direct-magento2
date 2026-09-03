<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Port;

use Hardcastle\LedgerDirect\Core\Port\ConfigProviderInterface;
use Hardcastle\LedgerDirect\Helper\SystemConfig;
use InvalidArgumentException;

/**
 * Platform side of the core's {@see ConfigProviderInterface}: a thin facade over the
 * module's SystemConfig helper, which is where Magento's store configuration is read.
 */
class MagentoConfigProvider implements ConfigProviderInterface
{
    public const CHAIN = 'XRPL';

    /**
     * Which payment method's "Enabled" flag decides whether an asset is accepted. There is
     * no separate per-asset toggle: the payment method *is* the asset here, so switching a
     * method off in the admin also stops the core from quoting in that asset.
     */
    private const PAYMENT_METHOD_BY_ASSET = [
        'XRP' => 'xrp_payment',
        'RLUSD' => 'xrpl_rlusd_payment',
        'USDC' => 'xrpl_usdc_payment',
    ];

    /**
     * @var SystemConfig
     */
    private SystemConfig $systemConfig;

    /**
     * @param SystemConfig $systemConfig
     */
    public function __construct(SystemConfig $systemConfig)
    {
        $this->systemConfig = $systemConfig;
    }

    /**
     * @inheritdoc
     */
    public function getNetwork(string $chain): string
    {
        $this->assertChain($chain);

        return $this->systemConfig->getNetwork();
    }

    /**
     * @inheritdoc
     */
    public function getDestinationAccount(string $chain): string
    {
        $this->assertChain($chain);

        return $this->systemConfig->getDestinationAccount();
    }

    /**
     * @inheritdoc
     */
    public function isAssetEnabled(string $chain, string $baseAsset): bool
    {
        $this->assertChain($chain);

        $paymentMethod = self::PAYMENT_METHOD_BY_ASSET[strtoupper($baseAsset)] ?? null;

        return $paymentMethod !== null && $this->systemConfig->isPaymentMethodActive($paymentMethod);
    }

    /**
     * @inheritdoc
     */
    public function getQuoteExpirySeconds(): int
    {
        return $this->systemConfig->getQuoteExpirySeconds();
    }

    /**
     * Reject any chain but XRPL
     *
     * The module only speaks XRPL. Asking for another chain is a wiring mistake, not a
     * configuration state, so it fails loudly rather than silently answering with XRPL's settings.
     *
     * @param string $chain
     * @return void
     */
    private function assertChain(string $chain): void
    {
        if ($chain !== self::CHAIN) {
            throw new InvalidArgumentException(
                sprintf('LedgerDirect supports chain "%s" only, got "%s".', self::CHAIN, $chain)
            );
        }
    }
}
