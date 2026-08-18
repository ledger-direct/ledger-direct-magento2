<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Provider;

use Exception;
use GuzzleHttp\Client;
use Hardcastle\LedgerDirect\Provider\Oracle\BinanceOracle;
use Hardcastle\LedgerDirect\Provider\Oracle\CoingeckoOracle;
use Hardcastle\LedgerDirect\Provider\Oracle\KrakenOracle;
use Psr\Log\LoggerInterface;

class XrpPriceProvider implements CryptoPriceProviderInterface
{
    public const CRYPTO_CODE = 'XRP';

    public const DEFAULT_ALLOWED_DIVERGENCE = 0.05;

    /**
     * @var Client
     */
    private Client $client;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Client $client
     * @param LoggerInterface $logger
     */
    public function __construct(Client $client, LoggerInterface $logger)
    {
        $this->client = $client;
        $this->logger = $logger;
    }

    /**
     * Gets the current XRP price by querying averaging multiple oracles
     *
     * @param string $code
     * @return float|false
     */
    public function getCurrentExchangeRate(string $code): float|false
    {
        $oracleResults = [];
        $filteredPrices = [];

        $oracles = [
            new BinanceOracle(),
            new CoingeckoOracle(),
            new KrakenOracle(),
        ];

        foreach ($oracles as $oracle) {
            try {
                $price = $oracle->prepare($this->client)->getCurrentPriceForPair(self::CRYPTO_CODE, $code);
                if ($price > 0.0) {
                    $oracleResults[] = $price;
                }
            } catch (Exception $exception) {
                $this->logger->warning(
                    sprintf('XRP price oracle %s failed: %s', get_class($oracle), $exception->getMessage())
                );
            }
        }

        if (count($oracleResults) === 0) {
            return false;
        }

        $avg = array_sum($oracleResults) / count($oracleResults);
        foreach ($oracleResults as $price) {
            if (abs($avg-$price) < $avg * self::DEFAULT_ALLOWED_DIVERGENCE) {
                $filteredPrices[] = $price;
            }
        }

        if (count($filteredPrices) > 0) {
            return array_sum($filteredPrices) / count($filteredPrices);
        }

        return false;
    }

    /**
     * Checks if the given XRP price is plausible.
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
