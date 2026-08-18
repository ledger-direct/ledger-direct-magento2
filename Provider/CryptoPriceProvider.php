<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Provider;

use Exception;
use GuzzleHttp\Client;
use Hardcastle\LedgerDirect\Provider\Oracle\BinanceOracle;
use Hardcastle\LedgerDirect\Provider\Oracle\CoingeckoOracle;
use Hardcastle\LedgerDirect\Provider\Oracle\KrakenOracle;
use Psr\Log\LoggerInterface;

class CryptoPriceProvider implements CryptoPriceProviderInterface
{
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
     * Gets the current price of the given base asset, quoted in the given currency
     *
     * @param string $baseAsset
     * @param string $quoteCurrency
     * @return float|false
     */
    public function getCurrentExchangeRate(string $baseAsset, string $quoteCurrency): float|false
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
                $price = $oracle->prepare($this->client)->getCurrentPriceForPair($baseAsset, $quoteCurrency);
                if ($price > 0.0) {
                    $oracleResults[] = $price;
                }
            } catch (Exception $exception) {
                $this->logger->warning(
                    sprintf(
                        '%s/%s price oracle %s failed: %s',
                        $baseAsset,
                        $quoteCurrency,
                        get_class($oracle),
                        $exception->getMessage()
                    )
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
     * Checks if the given price is plausible.
     *
     * @param float $price
     * @return bool
     */
    public function checkPricePlausibility(float $price): bool
    {
        return $price > 0.0;
    }
}
