<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Model;

use Hardcastle\LedgerDirect\Core\AssetDescriptor;
use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\ChainDescriptor;

/**
 * The module's payment methods as the map between the core's chain catalogue and Magento's
 * method codes
 *
 * One payment method per (chain, asset); the method *is* the asset here, so its "Enabled"
 * switch decides whether the core quotes in that asset. The catalogue says which chains and
 * assets exist; this class only holds the platform object each one is, by the intent's `type`.
 * A chain the core knows but this module does not yet carry stands in PENDING_CHAINS, and a
 * test keeps the two lists in step with the catalogue.
 */
class PaymentMethods
{
    /**
     * Catalogue chains without payment methods yet. A chain the core adds appears here first,
     * or the completeness test turns red.
     */
    public const PENDING_CHAINS = [ChainCatalog::STELLAR];

    /**
     * Method code by the core's intent type. The XRPL codes predate the catalogue and stay as
     * they are; new chains follow the core's type with underscores.
     */
    private const CODE_BY_TYPE = [
        'xrp-payment' => 'xrp_payment',
        'rlusd-payment' => 'xrpl_rlusd_payment',
        'usdc-payment' => 'xrpl_usdc_payment',
        'xahau-xah-payment' => 'xahau_xah_payment',
    ];

    /**
     * Every payment method code of the module
     *
     * @return string[]
     */
    public function codes(): array
    {
        return array_values(self::CODE_BY_TYPE);
    }

    /**
     * The chains this module carries payment methods for, in catalogue order
     *
     * @return string[]
     */
    public function supportedChains(): array
    {
        $chains = [];
        foreach (ChainCatalog::chains() as $chain) {
            if (!in_array($chain->chain, self::PENDING_CHAINS, true)) {
                $chains[] = $chain->chain;
            }
        }
        return $chains;
    }

    /**
     * Whether the module has payment methods on the given chain
     *
     * @param string $chain
     * @return bool
     */
    public function supportsChain(string $chain): bool
    {
        return in_array($chain, $this->supportedChains(), true);
    }

    /**
     * The method code for an intent type, or null for a type without a method
     *
     * @param string $type e.g. xrp-payment
     * @return string|null
     */
    public function codeForType(string $type): ?string
    {
        return self::CODE_BY_TYPE[$type] ?? null;
    }

    /**
     * The method code for an asset on a chain, or null when the module has no method for it
     *
     * @param string $chain
     * @param string $asset
     * @return string|null
     */
    public function codeFor(string $chain, string $asset): ?string
    {
        $asset = strtoupper($asset);
        if (!ChainCatalog::has($chain) || !ChainCatalog::chain($chain)->hasAsset($asset)) {
            return null;
        }
        return $this->codeForType(ChainCatalog::chain($chain)->asset($asset)->type);
    }

    /**
     * The chain and asset a method code stands for, or null for a code that is not ours
     *
     * @param string $code
     * @return array{0: ChainDescriptor, 1: AssetDescriptor}|null
     */
    public function chainAndAssetFor(string $code): ?array
    {
        $type = array_search($code, self::CODE_BY_TYPE, true);
        return $type === false ? null : ChainCatalog::forType($type);
    }
}
