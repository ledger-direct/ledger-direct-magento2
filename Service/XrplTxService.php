<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

use Hardcastle\LedgerDirect\Helper\Data;
use Hardcastle\LedgerDirect\Api\XrplTxRepositoryInterface;
use Hardcastle\LedgerDirect\Model\XrplTxRepository;
use Magento\Framework\App\ResourceConnection;

class XrplTxService
{
    public const DESTINATION_TAG_RANGE_MIN = 10000;

    public const DESTINATION_TAG_RANGE_MAX = 2140000000;

    protected Data $data;

    protected XrplClientService $clientService;

    protected XrplTxRepositoryInterface $xrplTxRepository;

    private ResourceConnection $connection;

    public function __construct(
        Data               $data,
        XrplClientService  $clientService,
        XrplTxRepositoryInterface $xrplTxRepository,
        ResourceConnection $connection
    )
    {
        $this->data = $data;
        $this->clientService = $clientService;
        $this->xrplTxRepository = $xrplTxRepository;
        $this->connection = $connection;
    }


    public function generateDestinationTag(string $accountAddress): int
    {
        // https://xrpl.org/source-and-destination-tags.html
        // https://xrpl.org/require-destination-tags.html

        while (true) {
            $destinationTag = random_int(self::DESTINATION_TAG_RANGE_MIN, self::DESTINATION_TAG_RANGE_MAX);

            $select = $this->connection->getConnection()
                ->select('destination_tag')
                ->from('xrpl_destination_tag')
                ->where('account = ?', $accountAddress)
                ->where('destination_tag = ?', $destinationTag);

            if (!$this->connection->getConnection()->fetchOne($select)) {
                $this->connection->getConnection()->insert('xrpl_destination_tag', ['destination_tag' => $destinationTag]);

                return $destinationTag;
            }
        }
    }

    /*
    public function findTransaction(string $destination, int $destinationTag): ?array
    {
        $statement = $this->connection->executeQuery(
            'SELECT * FROM xrpl_tx WHERE destination = :destination AND destination_tag = :destination_tag',
            ['destination' => $destination, 'destination_tag' => $destinationTag],
            ['destination' => PDO::PARAM_STR, 'destination_tag' => PDO::PARAM_INT]
        );
        $matches = $statement->fetchAllAssociative();

        if (!empty($matches)) {
            return $matches[0];
        }

        // TODO: If for whatever reason there are more than one matches, throw error

        return null;
    }
    */

    public function fetchTransaction(string $txHash): array
    {
        return $this->clientService->fetchTransaction($txHash);
    }

    public function fetchAccountTransactions(string $address, int $lastLedgerIndex = null): array
    {
        return $this->clientService->fetchAccountTransactions($address, $lastLedgerIndex);
    }

    public function syncAccountTransactions(string $address): void
    {
        $lastLedgerIndex = $this->xrplTxRepository->getLastLedgerIndex($address);

        if (!$lastLedgerIndex) {
            $lastLedgerIndex = null;
        }

        $transactions = $this->clientService->fetchAccountTransactions($address, $lastLedgerIndex);

        if (count($transactions)) {
            foreach ($transactions as $rawTx) {
                if($rawTx['validated']) {
                    $xrplTx = $this->xrplTxRepository->createFromArray($rawTx);
                    $this->xrplTxRepository->save($xrplTx);
                }
            }
        }
        // TODO: If marker is present, loop
    }

    /*
    public function resetDatabase(): void
    {
        //$this->connection->executeStatement('TRUNCATE TABLE xrpl_tx');
    }

    public function txToDb(array $transactions, string $address): void
    {
        $transactions = $this->filterIncomingTransactions($transactions, $address);
        $transactions = $this->filterNewTransactions($transactions);

        $rows = $this->hydrateRows($transactions);

        foreach ($rows as $row) {
            //$this->connection->insert('xrpl_tx', $row);
        }
    }


    private function filterIncomingTransactions(array $transactions, string $ownAddress): array
    {
        foreach ($transactions as $key => $transaction) {
            if ($transaction['tx']['Destination'] !== $ownAddress) {
                unset($transactions[$key]);
            }
        }

        return $transactions;
    }

    private function filterNewTransactions(array $transactions): array
    {
        $reducerFn = function ($hashes, $transaction) {
            $hashes[] = $transaction['tx']['hash'];

            return $hashes;
        };
        $hashes = array_reduce($transactions, $reducerFn, []);

        $statement = $this->connection->executeQuery(
            'SELECT hash FROM xrpl_tx WHERE hash IN (:hashes)',
            ['hashes' => $hashes],
            ['hashes' => Connection::PARAM_STR_ARRAY]
        );
        $matches = $statement->fetchAll();

        $lookup = [];
        foreach ($matches as $match) {
            $lookup[] = $match['hash'];
        }

        foreach ($transactions as $key => $transaction) {
            if (in_array($transaction['tx']['hash'], $lookup, true)) {
                unset($transactions[$key]);
            }
        }

        return $transactions;
    }

    private function hydrateRows(array $transactions): array
    {
        $rows = [];

        foreach ($transactions as $key => $transaction) {

            $ledgerIndex = (int)$transaction['tx']['ledger_index'];
            $transactionIndex = (int)$transaction['meta']['TransactionIndex'];
            // https://xrpl.org/connect-your-rippled-to-the-xrp-test-net.html#1-configure-your-server-to-connect-to-the-right-hub
            $networkId = 1; // TODO: Get a proper one

            $rows[] = [
                'id' => hex2bin(Uuid::randomHex()),
                'ledger_index' => $transaction['tx']['ledger_index'],
                'hash' => $transaction['tx']['hash'],
                'ctid' => $this->generateCtid($ledgerIndex, $transactionIndex, $networkId),
                'account' => $transaction['tx']['Account'],
                'destination' => $transaction['tx']['Destination'],
                'destination_tag' => $transaction['tx']['DestinationTag'] ?? null,
                'date' => $transaction['tx']['date'],
                'meta' => json_encode($transaction['meta']),
                'tx' => json_encode($transaction['tx'])
            ];
        }

        return $rows;
    }

    private function generateCtid(int $ledgerIndex, int $transactionIndex, int $networkId): string
    {
        // TODO: Build a proper function in XRPL_PHP, currently it's cargo-culting
        // https://github.com/XRPLF/XRPL-Standards/discussions/91
        $num = ((0xc0000000 + $ledgerIndex) << 32) + ($transactionIndex << 16) + $networkId;

        return strtoupper(dechex($num));
    }

    */
}
