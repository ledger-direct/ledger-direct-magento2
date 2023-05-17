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

    public function getExchangeRate(string $token, string $iso): mixed;
}
