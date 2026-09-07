<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Port;

use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use Hardcastle\LedgerDirect\Port\MagentoXrplTransactionRepository;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The storage port against a stubbed DB adapter. The atomic counter and the duplicate
 * handling are what matter here; the SQL itself is exercised live against MariaDB
 * (see the PR test plan), not through mocks.
 */
class MagentoXrplTransactionRepositoryTest extends TestCase
{
    private const ACCOUNT = 'rMerchantAccount';

    /** @var AdapterInterface|MockObject */
    private $connection;

    private MagentoXrplTransactionRepository $repository;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('quoteIdentifier')->willReturnCallback(fn (string $name) => '`' . $name . '`');

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($this->connection);
        // A configured db/table_prefix must reach every statement.
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $table) => 'pfx_' . $table);

        $this->repository = new MagentoXrplTransactionRepository($resourceConnection);
    }

    public function testAnExistingCounterIsAdvancedWithASingleUpdate(): void
    {
        $this->connection->expects($this->once())
            ->method('update')
            ->with('pfx_ledger_direct_xrpl_destination_tag', $this->anything(), ['destination_account = ?' => self::ACCOUNT])
            ->willReturn(1);
        $this->connection->expects($this->never())->method('insert');
        $this->givenLastInsertId('7');

        $this->assertSame(7, $this->repository->nextDestinationTagSequence(self::ACCOUNT));
    }

    /**
     * The port contract: a fresh counter starts at a random value in [0, 2^31 - 1], never at
     * a fixed 0 - two installations on one receiving account must not hand out the same tags.
     */
    public function testTheFirstSequenceForAnAccountIsARandomStart(): void
    {
        $inserted = null;
        $this->connection->expects($this->once())->method('update')->willReturn(0);
        $this->connection->expects($this->once())
            ->method('insert')
            ->with(
                'pfx_ledger_direct_xrpl_destination_tag',
                $this->callback(function (array $row) use (&$inserted): bool {
                    $inserted = $row;

                    return $row['destination_account'] === self::ACCOUNT
                        && $row['sequence'] >= 0
                        && $row['sequence'] <= MagentoXrplTransactionRepository::MAX_RANDOM_SEQUENCE_START;
                })
            );

        $first = $this->repository->nextDestinationTagSequence(self::ACCOUNT);

        $this->assertSame($inserted['sequence'], $first, 'the value stored is the value issued');
    }

    /**
     * Two first calls racing: the insert that loses on the primary key must not surface as an
     * error, nor hand out 0 twice - it falls back to advancing the row the winner created.
     */
    public function testALostInsertRaceFallsBackToAdvancingTheCounter(): void
    {
        $this->connection->expects($this->exactly(2))->method('update')->willReturnOnConsecutiveCalls(0, 1);
        $this->connection->expects($this->once())->method('insert')->willThrowException(new DuplicateException('dup'));
        $this->givenLastInsertId('1');

        $this->assertSame(1, $this->repository->nextDestinationTagSequence(self::ACCOUNT));
    }

    public function testFindExistingHashesSkipsTheDatabaseForAnEmptyList(): void
    {
        $this->connection->expects($this->never())->method('select');

        $this->assertSame([], $this->repository->findExistingHashes([]));
    }

    public function testFindExistingHashesReturnsStrings(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->with('hash IN (?)', ['A', 'B'])->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchCol')->willReturn(['A']);

        $this->assertSame(['A'], $this->repository->findExistingHashes(['A', 'B']));
    }

    /**
     * The unique index on `hash` doing its job during a concurrent sync is the desired end
     * state, not an error.
     */
    public function testStoringADuplicateTransactionIsHarmless(): void
    {
        $this->connection->expects($this->exactly(2))
            ->method('insert')
            ->willReturnCallback(function (string $table, array $row): int {
                $this->assertSame('pfx_ledger_direct_xrpl_tx', $table);
                $this->assertSame('testnet', $row['network'], 'the network is stored with the row');
                $this->assertSame('{"delivered_amount":"40000000"}', $row['meta']);
                if ($row['hash'] === 'DUPLICATE') {
                    throw new DuplicateException('dup');
                }

                return 1;
            });

        $this->repository->saveTransactions([
            $this->givenTransaction('DUPLICATE'),
            $this->givenTransaction('FRESH'),
        ]);
    }

    public function testFindTransactionsHydratesTheStoredRowsNewestFirst(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->expects($this->once())->method('order')->with(['ledger_index DESC', 'id DESC'])->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchAll')->willReturn([[
            'id' => '1',
            'network' => 'testnet',
            'ledger_index' => '12345',
            'hash' => 'HASH',
            'ctid' => 'C000303900000001',
            'account' => 'rSender',
            'destination' => self::ACCOUNT,
            'destination_tag' => '4294967295',
            'date' => '700000000',
            'meta' => '{"delivered_amount":"40000000"}',
            'tx' => '{"TransactionType":"Payment"}',
        ]]);

        $transactions = $this->repository->findTransactions(self::ACCOUNT, 4294967295);

        $this->assertCount(1, $transactions);
        $transaction = $transactions[0];
        $this->assertSame('testnet', $transaction->network);
        $this->assertSame('12345', $transaction->ledgerIndex);
        $this->assertSame(4294967295, $transaction->destinationTag);
        $this->assertSame('C000303900000001', $transaction->ctid);
        $this->assertSame(40.0, $transaction->getDeliveredAmount());
        $this->assertSame(['TransactionType' => 'Payment'], $transaction->tx);
    }

    public function testFindTransactionsIsEmptyWhenNothingIsStored(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchAll')->willReturn([]);

        $this->assertSame([], $this->repository->findTransactions(self::ACCOUNT, 1));
    }

    /**
     * The cursor is scoped by account and network: a global MAX() would pin the testnet cursor
     * above every testnet ledger as soon as a single mainnet row exists.
     */
    public function testLastSyncedLedgerIndexIsScopedByAccountAndNetwork(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->expects($this->exactly(2))->method('where')
            ->willReturnCallback(function (string $condition, $value) use ($select) {
                $this->assertContains([$condition, $value], [
                    ['destination = ?', self::ACCOUNT],
                    ['network = ?', 'testnet'],
                ]);

                return $select;
            });
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchOne')->willReturn('20451439');

        $this->assertSame('20451439', $this->repository->getLastSyncedLedgerIndex(self::ACCOUNT, 'testnet'));
    }

    public function testLastSyncedLedgerIndexIsNullOnAnEmptyTable(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchOne')->willReturn(null);

        $this->assertNull($this->repository->getLastSyncedLedgerIndex(self::ACCOUNT, 'testnet'));
    }

    public function testTruncateHitsThePrefixedTransactionTable(): void
    {
        $this->connection->expects($this->once())->method('truncateTable')->with('pfx_ledger_direct_xrpl_tx');

        $this->repository->truncate();
    }

    /**
     * What `SELECT LAST_INSERT_ID()` hands back after the counter UPDATE.
     */
    private function givenLastInsertId(string $value): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchOne')->with($select)->willReturn($value);
    }

    private function givenTransaction(string $hash): XrplTransaction
    {
        return new XrplTransaction(
            network: 'testnet',
            ledgerIndex: '90',
            hash: $hash,
            ctid: 'C000005A00000001',
            account: 'rSender',
            destination: self::ACCOUNT,
            destinationTag: 10001,
            date: 0,
            meta: ['delivered_amount' => '40000000'],
            tx: ['TransactionType' => 'Payment'],
        );
    }
}
