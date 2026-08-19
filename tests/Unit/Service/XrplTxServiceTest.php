<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Service;

use Hardcastle\LedgerDirect\Api\Data\XrplTxInterface;
use Hardcastle\LedgerDirect\Api\XrplTxRepositoryInterface;
use Hardcastle\LedgerDirect\Helper\Data;
use Hardcastle\LedgerDirect\Service\XrplClientService;
use Hardcastle\LedgerDirect\Service\XrplTxService;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class XrplTxServiceTest extends TestCase
{
    /** @var Data|MockObject */
    private $data;

    /** @var XrplClientService|MockObject */
    private $clientService;

    /** @var XrplTxRepositoryInterface|MockObject */
    private $xrplTxRepository;

    /** @var ResourceConnection|MockObject */
    private $connection;

    /** @var AdapterInterface|MockObject */
    private $adapter;

    /** @var Select|MockObject */
    private $select;

    private XrplTxService $service;

    protected function setUp(): void
    {
        $this->data = $this->createMock(Data::class);
        $this->clientService = $this->createMock(XrplClientService::class);
        $this->xrplTxRepository = $this->createMock(XrplTxRepositoryInterface::class);
        $this->connection = $this->createMock(ResourceConnection::class);
        $this->adapter = $this->createMock(AdapterInterface::class);
        $this->select = $this->createMock(Select::class);

        $this->select->method('from')->willReturnSelf();
        $this->select->method('where')->willReturnSelf();
        $this->adapter->method('select')->willReturn($this->select);
        $this->connection->method('getConnection')->willReturn($this->adapter);

        $this->service = new XrplTxService(
            $this->data,
            $this->clientService,
            $this->xrplTxRepository,
            $this->connection
        );
    }

    public function testSyncAccountTransactionsSkipsNonPaymentAndDuplicateOnly()
    {
        $duplicateTx = [
            'validated' => true,
            'tx' => ['TransactionType' => 'Payment', 'hash' => 'DUP_HASH'],
        ];
        $notValidatedTx = [
            'validated' => false,
            'tx' => ['TransactionType' => 'Payment', 'hash' => 'IGNORED_HASH'],
        ];
        $nonPaymentTx = [
            'validated' => true,
            'tx' => ['TransactionType' => 'OfferCreate', 'hash' => 'OFFER_HASH'],
        ];

        $this->xrplTxRepository->method('getLastLedgerIndex')->willReturn(null);
        $this->clientService->method('fetchAccountTransactions')
            ->willReturn([$notValidatedTx, $nonPaymentTx, $duplicateTx]);

        // Only the duplicate Payment tx reaches the hash lookup; simulate "already stored".
        $this->adapter->method('fetchOne')->willReturn('DUP_HASH');

        $this->xrplTxRepository->expects($this->never())->method('createFromArray');
        $this->xrplTxRepository->expects($this->never())->method('save');

        $this->service->syncAccountTransactions('rAddress');
    }

    public function testSyncAccountTransactionsStoresNewValidatedPaymentTransaction()
    {
        $newTx = [
            'validated' => true,
            'tx' => ['TransactionType' => 'Payment', 'hash' => 'NEW_HASH'],
        ];

        $this->xrplTxRepository->method('getLastLedgerIndex')->willReturn(null);
        $this->clientService->method('fetchAccountTransactions')->willReturn([$newTx]);

        // Not stored yet.
        $this->adapter->method('fetchOne')->willReturn(false);

        $createdEntity = $this->createMock(XrplTxInterface::class);
        $this->xrplTxRepository->expects($this->once())
            ->method('createFromArray')
            ->with($newTx)
            ->willReturn($createdEntity);
        $this->xrplTxRepository->expects($this->once())
            ->method('save')
            ->with($createdEntity);

        $this->service->syncAccountTransactions('rAddress');
    }
}
