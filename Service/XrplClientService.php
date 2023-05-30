<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

use HArdcastle\LedgerDirect\Helper\SystemConfig;
use XRPL_PHP\Client\JsonRpcClient;
use XRPL_PHP\Core\Networks;
use XRPL_PHP\Models\Account\AccountTxRequest;
use XRPL_PHP\Models\Transaction\TxRequest;

class XrplClientService
{
    private SystemConfig $configHelper;

    private JsonRpcClient $client;

    public function __construct(SystemConfig $configHelper) {
        $this->configHelper = $configHelper ;

        $this->_initClient();
    }

    /**
     * @param string $txHash
     * @return array
     */
    public function fetchTransaction(string $txHash): array
    {
        $req = new TxRequest($txHash);
        $res = $this->client->syncRequest($req);

        return $res->getResult();
    }

    /**
     * @param string $address
     * @param int|null $lastLedgerIndex
     * @return array
     */
    public function fetchAccountTransactions(string $address, ?int $lastLedgerIndex): array
    {
        $req = new AccountTxRequest($address, $lastLedgerIndex);
        $res = $this->client->syncRequest($req);

        return $res->getResult()['transactions'];
    }

    public function getNetwork(): array
    {
        if(!$this->configHelper->isTest()) {
            return Networks::getNetwork('mainnet');
        }

        return Networks::getNetwork('testnet');
    }

    private function _initClient(): void
    {
        $jsonRpcUrl = $this->getNetwork()['jsonRpcUrl'];
        $this->client = new JsonRpcClient($jsonRpcUrl);
    }
}
