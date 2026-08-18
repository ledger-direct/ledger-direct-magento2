<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Provider;

/**
 * Known XRPL issued-currency identity (issuer + on-ledger currency code) for the stablecoins
 * this module supports. These are official, fixed issuers - not merchant-configurable - since
 * getting one wrong would point customer payments at a trustline that doesn't exist or isn't
 * the real token.
 */
class StablecoinRegistry
{
    public const RLUSD_CODE = 'RLUSD';

    public const USDC_CODE = 'USDC';

    private const RLUSD_SETTINGS = [
        'mainnet' => [
            'issuer' => 'rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De',
            'currency' => '524C555344000000000000000000000000000000',
        ],
        'testnet' => [
            'issuer' => 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV',
            'currency' => '524C555344000000000000000000000000000000',
        ],
    ];

    private const USDC_SETTINGS = [
        'mainnet' => [
            'issuer' => 'rGm7WCVp9gb4jZHWTEtGUr4dd74z2XuWhE',
            'currency' => '5553444300000000000000000000000000000000',
        ],
        'testnet' => [
            'issuer' => 'rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt',
            'currency' => '5553444300000000000000000000000000000000',
        ],
    ];

    /**
     * Build an XRPL issued-currency amount object for RLUSD
     *
     * @param bool $isTestnet
     * @param string $value Decimal string, e.g. "12.34"
     * @return array{currency: string, value: string, issuer: string}
     */
    public function getRlusdAmount(bool $isTestnet, string $value): array
    {
        return $this->buildAmount(self::RLUSD_SETTINGS, $isTestnet, $value);
    }

    /**
     * Build an XRPL issued-currency amount object for USDC
     *
     * @param bool $isTestnet
     * @param string $value Decimal string, e.g. "12.34"
     * @return array{currency: string, value: string, issuer: string}
     */
    public function getUsdcAmount(bool $isTestnet, string $value): array
    {
        return $this->buildAmount(self::USDC_SETTINGS, $isTestnet, $value);
    }

    /**
     * Pick the network-specific issuer/currency and assemble the amount object
     *
     * @param array $settings
     * @param bool $isTestnet
     * @param string $value
     * @return array{currency: string, value: string, issuer: string}
     */
    private function buildAmount(array $settings, bool $isTestnet, string $value): array
    {
        $network = $isTestnet ? $settings['testnet'] : $settings['mainnet'];

        return [
            'currency' => $network['currency'],
            'value' => $value,
            'issuer' => $network['issuer'],
        ];
    }
}
