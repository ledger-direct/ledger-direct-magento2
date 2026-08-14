<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

use Hardcastle\LedgerDirect\Helper\SystemConfig;
use Magento\Framework\HTTP\Client\Curl;

/**
 * Talks to an XRP Ledger node via JSON-RPC using Magento's own HTTP client.
 *
 * No third-party XRPL SDK is required — this keeps the module's dependency
 * footprint small and avoids shipping a second Guzzle into the Magento project.
 */
class XrplClientService
{
    private const TESTNET_JSON_RPC_URL = 'https://s.altnet.rippletest.net:51234/';

    private const MAINNET_JSON_RPC_URL = 'https://xrplcluster.com/';

    private SystemConfig $configHelper;

    private Curl $curl;

    public function __construct(SystemConfig $configHelper, Curl $curl)
    {
        $this->configHelper = $configHelper;
        $this->curl = $curl;
    }

    /**
     * Fetches a single transaction by its hash.
     *
     * @return array The JSON-RPC "result" payload, or an empty array on error.
     */
    public function fetchTransaction(string $txHash): array
    {
        return $this->jsonRpc('tx', [[
            'transaction' => $txHash,
            'binary' => false,
            'api_version' => 1,
        ]]) ?? [];
    }

    /**
     * Fetches recent account transactions (most recent first).
     *
     * We deliberately request the newest transactions with a generous limit rather
     * than relying on a stored "last ledger index": a stale index can fall outside
     * the node's available range (e.g. after a testnet reset), which makes the whole
     * query fail. De-duplication happens on storage.
     *
     * @return array The list of transaction envelopes, or an empty array on error.
     */
    public function fetchAccountTransactions(string $address, ?int $lastLedgerIndex = null): array
    {
        $result = $this->jsonRpc('account_tx', [[
            'account' => $address,
            'ledger_index_min' => -1,
            'ledger_index_max' => -1,
            'binary' => false,
            'forward' => false,
            'limit' => 200,
            'api_version' => 1,
        ]]);

        return $result['transactions'] ?? [];
    }

    /**
     * @return array{network: string, jsonRpcUrl: string}
     */
    public function getNetwork(): array
    {
        if (!$this->configHelper->isTest()) {
            return ['network' => 'mainnet', 'jsonRpcUrl' => self::MAINNET_JSON_RPC_URL];
        }

        return ['network' => 'testnet', 'jsonRpcUrl' => self::TESTNET_JSON_RPC_URL];
    }

    /**
     * Performs a JSON-RPC request against the active network and returns the
     * "result" payload, or null on any transport / JSON / XRPL error.
     */
    private function jsonRpc(string $method, array $params): ?array
    {
        $body = json_encode([
            'method' => $method,
            'params' => $params,
        ]);

        try {
            $this->curl->addHeader('Content-Type', 'application/json');
            $this->curl->post($this->getNetwork()['jsonRpcUrl'], $body);
        } catch (\Throwable $e) {
            return null;
        }

        $status = $this->curl->getStatus();
        if ($status < 200 || $status >= 300) {
            return null;
        }

        $payload = json_decode((string) $this->curl->getBody(), true);
        if (!is_array($payload) || !isset($payload['result']) || !is_array($payload['result'])) {
            return null;
        }

        $result = $payload['result'];
        if (($result['status'] ?? null) === 'error') {
            return null;
        }

        return $result;
    }
}
