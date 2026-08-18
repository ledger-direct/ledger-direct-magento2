<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Provider\Oracle;

interface OracleInterface
{
    /**
     * Get the current price for a currency pair
     *
     * @param string $code1
     * @param string $code2
     * @return float
     */
    public function getCurrentPriceForPair(string $code1, string $code2): float;
}
