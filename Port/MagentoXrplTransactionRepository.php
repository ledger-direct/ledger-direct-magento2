<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Port;

use Hardcastle\LedgerDirect\Core\Port\XrplTransactionRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Framework\DB\Sql\Expression;

/**
 * Platform side of the core's {@see XrplTransactionRepositoryInterface}, on Magento's
 * default DB connection. Storage primitives only - the sync, dedup and destination-tag
 * derivation around them live in the core.
 *
 * Table names go through ResourceConnection::getTableName() so a configured
 * `db/table_prefix` is honoured, as INVARIANTS.md asks of every platform adapter.
 */
class MagentoXrplTransactionRepository implements XrplTransactionRepositoryInterface
{
    public const TX_TABLE = 'ledger_direct_xrpl_tx';

    public const TAG_TABLE = 'ledger_direct_xrpl_destination_tag';

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resourceConnection;

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * @inheritdoc
     *
     * The counter row stores the sequence number issued last. Advancing it is a single
     * UPDATE, so two checkouts racing on the same merchant account can never read the
     * same value - a select-then-update pair could, and two orders would then share a
     * destination tag. LAST_INSERT_ID(expr) both stores the new value and makes it
     * readable per connection, so the value read back afterwards is this caller's own.
     *
     * The first call for an account inserts the row at 0. If two first calls race, one
     * insert loses on the primary key and simply falls back to the UPDATE path.
     */
    public function nextDestinationTagSequence(string $destinationAccount): int
    {
        $connection = $this->getConnection();
        $table = $this->getTableName(self::TAG_TABLE);
        $sequence = $connection->quoteIdentifier('sequence');

        $advance = function () use ($connection, $table, $sequence, $destinationAccount): int {
            return $connection->update(
                $table,
                ['sequence' => new Expression('LAST_INSERT_ID(' . $sequence . ' + 1)')],
                ['destination_account = ?' => $destinationAccount]
            );
        };

        if ($advance() === 0) {
            try {
                $connection->insert($table, ['destination_account' => $destinationAccount, 'sequence' => 0]);

                return 0;
            } catch (DuplicateException $exception) {
                $advance();
            }
        }

        $lastIssued = $connection->select()->from('', [new Expression('LAST_INSERT_ID()')]);

        return (int) $connection->fetchOne($lastIssued);
    }

    /**
     * @inheritdoc
     */
    public function findExistingHashes(array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }

        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getTableName(self::TX_TABLE), ['hash'])
            ->where('hash IN (?)', array_values($hashes));

        return array_map('strval', $connection->fetchCol($select));
    }

    /**
     * @inheritdoc
     */
    public function saveTransactions(array $transactions): void
    {
        $connection = $this->getConnection();
        $table = $this->getTableName(self::TX_TABLE);

        foreach ($transactions as $transaction) {
            try {
                $connection->insert($table, [
                    'ledger_index' => $transaction->ledgerIndex,
                    'hash' => $transaction->hash,
                    'ctid' => $transaction->ctid,
                    'account' => $transaction->account,
                    'destination' => $transaction->destination,
                    'destination_tag' => $transaction->destinationTag,
                    'date' => $transaction->date,
                    'meta' => json_encode($transaction->meta, JSON_THROW_ON_ERROR),
                    'tx' => json_encode($transaction->tx, JSON_THROW_ON_ERROR),
                ]);
            } catch (DuplicateException $exception) {
                // The unique index on `hash` doing its job: a concurrent sync stored this
                // transaction between the core's dedup check and this insert. The row is
                // already there, which is the desired end state.
                continue;
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function findTransaction(string $destination, int $destinationTag): ?XrplTransaction
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getTableName(self::TX_TABLE))
            ->where('destination = ?', $destination)
            ->where('destination_tag = ?', $destinationTag)
            ->limit(1);

        $row = $connection->fetchRow($select);

        return is_array($row) && $row !== [] ? $this->hydrate($row) : null;
    }

    /**
     * @inheritdoc
     */
    public function getLastSyncedLedgerIndex(): ?string
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getTableName(self::TX_TABLE), ['last' => new Expression('MAX(ledger_index)')]);

        $lastSyncedLedgerIndex = $connection->fetchOne($select);

        return $lastSyncedLedgerIndex === null || $lastSyncedLedgerIndex === false
            ? null
            : (string) $lastSyncedLedgerIndex;
    }

    /**
     * @inheritdoc
     */
    public function truncate(): void
    {
        $this->getConnection()->truncateTable($this->getTableName(self::TX_TABLE));
    }

    /**
     * The default DB connection
     *
     * @return AdapterInterface
     */
    private function getConnection(): AdapterInterface
    {
        return $this->resourceConnection->getConnection();
    }

    /**
     * The physical table name, including a configured table prefix
     *
     * @param string $table
     * @return string
     */
    private function getTableName(string $table): string
    {
        return $this->resourceConnection->getTableName($table);
    }

    /**
     * Build the core's transaction object from a stored row
     *
     * @param array $row
     * @return XrplTransaction
     */
    private function hydrate(array $row): XrplTransaction
    {
        return new XrplTransaction(
            ledgerIndex: (string) $row['ledger_index'],
            hash: (string) $row['hash'],
            ctid: (string) $row['ctid'],
            account: (string) $row['account'],
            destination: (string) $row['destination'],
            destinationTag: $row['destination_tag'] === null ? null : (int) $row['destination_tag'],
            date: (int) $row['date'],
            meta: $this->decodeJsonColumn($row['meta'] ?? null),
            tx: $this->decodeJsonColumn($row['tx'] ?? null),
        );
    }

    /**
     * Decode a JSON text column, treating anything unreadable as empty
     *
     * @param mixed $value
     * @return array
     */
    private function decodeJsonColumn(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
