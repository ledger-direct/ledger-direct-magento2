<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Api;

interface CryptoPriceServiceInterface
{
    /**
     * Get crypto price for Order
     *
     * @api
     * @param int $orderId
     *
     * @return mixed
     */
    public function getPrice(int $orderId): mixed;

    /**
     * Get the exchange rate for a token/ISO currency pair
     *
     * @param string $token
     * @param string $iso
     * @return mixed
     */
    public function getExchangeRate(string $token, string $iso): mixed;
}
