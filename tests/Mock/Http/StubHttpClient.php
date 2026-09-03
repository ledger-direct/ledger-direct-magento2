<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Mock\Http;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that answers the outbound calls the core makes during these tests - the
 * price oracles and the XRPL JSON-RPC endpoint - without touching the network.
 *
 * Oracles that are not stubbed (Binance) get an empty body and take the same "oracle
 * unavailable" path they would take in production; the core averages whatever is left.
 */
class StubHttpClient implements ClientInterface
{
    private float $price;

    /** @var array<int, array<string, mixed>> */
    private array $ledgerTransactions;

    private int $rpcHttpStatus;

    /** @var string[] every request URI seen, for assertions on what was (not) called */
    public array $requestedUris = [];

    /**
     * @param array<int, array<string, mixed>> $ledgerTransactions
     */
    public function __construct(float $price = 2.5, array $ledgerTransactions = [], int $rpcHttpStatus = 200)
    {
        $this->price = $price;
        $this->ledgerTransactions = $ledgerTransactions;
        $this->rpcHttpStatus = $rpcHttpStatus;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $uri = (string) $request->getUri();
        $this->requestedUris[] = $uri;

        if (str_contains($uri, 'api.kraken.com')) {
            return self::json([
                'result' => [
                    'XXRPZEUR' => ['c' => [(string) $this->price, '1000']],
                ],
            ]);
        }

        if (str_contains($uri, 'api.coingecko.com')) {
            parse_str((string) $request->getUri()->getQuery(), $query);
            $baseId = (string) ($query['ids'] ?? '');
            $quoteId = (string) ($query['vs_currencies'] ?? '');

            return self::json([$baseId => [$quoteId => $this->price]]);
        }

        if ($request->getMethod() === 'POST') {
            // XRPL JSON-RPC (account_tx)
            return self::json([
                'result' => [
                    'transactions' => $this->ledgerTransactions,
                    'marker' => null,
                ],
            ], $this->rpcHttpStatus);
        }

        return self::json([]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function json(array $payload, int $status = 200): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
