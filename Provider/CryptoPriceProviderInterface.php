<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Provider;

interface CryptoPriceProviderInterface
{
    /**
     * Get the current price of the given base asset, quoted in the given currency
     *
     * @param string $baseAsset
     * @param string $quoteCurrency
     * @return float|false
     */
    public function getCurrentExchangeRate(string $baseAsset, string $quoteCurrency): float|false;

    /**
     * Check whether the given price is plausible
     *
     * @param float $price
     * @return bool
     */
    public function checkPricePlausibility(float $price): bool;
}
