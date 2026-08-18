<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

use GuzzleHttp\Exception\GuzzleException;
use Hardcastle\LedgerDirect\Helper\Data;
use Hardcastle\LedgerDirect\Api\XrplTxRepositoryInterface;
use Hardcastle\LedgerDirect\Model\XrplTxRepository;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;

class XrplTxService
{
    public const DESTINATION_TAG_RANGE_MIN = 10000;

    public const DESTINATION_TAG_RANGE_MAX = 2140000000;

    /**
     * @var Data
     */
    protected Data $data;

    /**
     * @var XrplClientService
     */
    protected XrplClientService $clientService;

    /**
     * @var XrplTxRepositoryInterface
     */
    protected XrplTxRepositoryInterface $xrplTxRepository;

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $connection;

    /**
     * @param Data $data
     * @param XrplClientService $clientService
     * @param XrplTxRepositoryInterface $xrplTxRepository
     * @param ResourceConnection $connection
     */
    public function __construct(
        Data               $data,
        XrplClientService  $clientService,
        XrplTxRepositoryInterface $xrplTxRepository,
        ResourceConnection $connection
    ) {
        $this->data = $data;
        $this->clientService = $clientService;
        $this->xrplTxRepository = $xrplTxRepository;
        $this->connection = $connection;
    }

    /**
     * Generate a destination tag not already reserved for the given account
     *
     * @param string $accountAddress
     * @return int
     */
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
                $this->connection->getConnection()->insert(
                    'xrpl_destination_tag',
                    ['destination_tag' => $destinationTag]
                );

                return $destinationTag;
            }
        }
    }

    /**
     * Find a stored transaction matching the destination account and tag
     *
     * @param string $destination
     * @param int $destinationTag
     * @return array|null
     */
    public function findTransaction(string $destination, int $destinationTag): ?array
    {
        $select = $this->connection->getConnection()
            ->select('*')
            ->from('xrpl_tx')
            ->where('destination = ?', $destination)
            ->where('destination_tag = ?', $destinationTag);
        $matches = $this->connection->getConnection()->fetchAll($select);

        if (!empty($matches)) {
            return $matches[0];
        }

        return null;
    }

    /**
     * Fetch a single transaction from the XRPL node by its hash
     *
     * @param string $txHash
     * @return array
     * @throws GuzzleException
     */
    public function fetchTransaction(string $txHash): array
    {
        return $this->clientService->fetchTransaction($txHash);
    }

    /**
     * Fetch recent transactions for an XRPL account
     *
     * @param string $address
     * @param int|null $lastLedgerIndex
     * @return array
     * @throws GuzzleException
     */
    public function fetchAccountTransactions(string $address, int $lastLedgerIndex = null): array
    {
        return $this->clientService->fetchAccountTransactions($address, $lastLedgerIndex);
    }

    /**
     * Fetch and store validated Payment transactions for an XRPL account, skipping duplicates
     *
     * @param string $address
     * @return void
     * @throws GuzzleException|LocalizedException
     */
    public function syncAccountTransactions(string $address): void
    {
        $lastLedgerIndex = $this->xrplTxRepository->getLastLedgerIndex($address) ?: null;

        $transactions = $this->clientService->fetchAccountTransactions($address, $lastLedgerIndex);

        foreach ($transactions as $rawTx) {
            $tx = $rawTx['tx'] ?? null;
            $validated = $rawTx['validated'] ?? false;

            if (!$validated || !is_array($tx) || ($tx['TransactionType'] ?? null) !== 'Payment') {
                continue;
            }

            // Skip transactions we already stored (the table has no unique hash index yet).
            $hash = $tx['hash'] ?? null;
            if ($hash !== null && $this->transactionExistsByHash($hash)) {
                continue;
            }

            $xrplTx = $this->xrplTxRepository->createFromArray($rawTx);
            $this->xrplTxRepository->save($xrplTx);
        }
    }

    /**
     * Check whether a transaction with the given hash is already stored
     *
     * @param string $hash
     * @return bool
     */
    private function transactionExistsByHash(string $hash): bool
    {
        $connection = $this->connection->getConnection();
        $select = $connection->select()->from('xrpl_tx', 'hash')->where('hash = ?', $hash);

        return (bool) $connection->fetchOne($select);
    }
}
