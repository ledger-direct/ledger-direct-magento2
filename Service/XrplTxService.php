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


    /**
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
     * @param string $txHash
     * @return array
     * @throws GuzzleException
     */
    public function fetchTransaction(string $txHash): array
    {
        return $this->clientService->fetchTransaction($txHash);
    }

    /**
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
     * @param string $address
     * @throws GuzzleException|LocalizedException
     */
    public function syncAccountTransactions(string $address): void
    {
        $lastLedgerIndex = $this->xrplTxRepository->getLastLedgerIndex($address) ?? null;

        if (!$lastLedgerIndex) {
            $lastLedgerIndex = null;
        }

        $transactions = $this->clientService->fetchAccountTransactions($address, $lastLedgerIndex);

        foreach ($transactions as $rawTx) {
            if($rawTx['validated'] && $rawTx['tx']['TransactionType'] === 'Payment') {
                $xrplTx = $this->xrplTxRepository->createFromArray($rawTx);
                $this->xrplTxRepository->save($xrplTx);
            }
        }
    }
}
