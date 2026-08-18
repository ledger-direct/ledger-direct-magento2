<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Provider;

interface CryptoPriceProviderInterface
{
    /**
     * Get the current exchange rate for the given currency code
     *
     * @param string $code
     * @return float|false
     */
    public function getCurrentExchangeRate(string $code): float|false;

    /**
     * Check whether the given price is plausible
     *
     * @param float $price
     * @return bool
     */
    public function checkPricePlausibility(float $price): bool;
}
