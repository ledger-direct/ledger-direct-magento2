<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Provider;

use Exception;
use GuzzleHttp\Client;
use Hardcastle\LedgerDirect\Provider\Oracle\BinanceOracle;

class XrpPriceProvider implements CryptoPriceProviderInterface
{
    public const CRYPTO_CODE = 'XRP';

    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * Gets the current XRP price by querying averaging multiple oracles
     *
     * @param string $code
     * @return float
     */
    public function getCurrentExchangeRate(string $code): float
    {
        $oracle = new BinanceOracle();

        try {
            $price = $oracle->prepare($this->client)->getCurrentPriceForPair(self::CRYPTO_CODE, $code);
            if (!$this->checkPricePlausibility($price)) {
                // TODO: throw exception
            }

            return $price;
        } catch (Exception $exception) {
            // TODO: Log error
        }

        return 0; //TODO: Catch in Frontend and do not use in quote!
    }

    /**
     *
     *
     * @param float $price
     * @return bool
     */
    public function checkPricePlausibility(float $price): bool
    {
        if ($price > 0.0) {
            return true;
        }

        return false ;
    }
}
