<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Model;

use Hardcastle\LedgerDirect\Model\Explorer;
use PHPUnit\Framework\TestCase;

class ExplorerTest extends TestCase
{
    public function testEachChainAndNetworkHasItsExplorer(): void
    {
        $this->assertSame('https://livenet.xrpl.org/transactions/H', (new Explorer())->transactionUrl('XRPL', 'mainnet', 'H'));
        $this->assertSame('https://testnet.xrpl.org/transactions/H', (new Explorer())->transactionUrl('XRPL', 'testnet', 'H'));
        $this->assertSame('https://explorer.xahau.network/tx/H', (new Explorer())->transactionUrl('XAHAU', 'mainnet', 'H'));
        $this->assertSame('https://explorer.xahau-test.net/tx/H', (new Explorer())->transactionUrl('XAHAU', 'testnet', 'H'));
    }

    public function testNoLinkWithoutAHashOrAnExplorer(): void
    {
        $this->assertNull((new Explorer())->transactionUrl('XRPL', 'testnet', null));
        $this->assertNull((new Explorer())->transactionUrl('XRPL', 'testnet', ''));
        $this->assertNull((new Explorer())->transactionUrl('STELLAR', 'testnet', 'H'));
        $this->assertNull((new Explorer())->transactionUrl('XRPL', 'devnet', 'H'));
        $this->assertSame('', (new Explorer())->transactionBase('STELLAR', 'testnet'));
    }
}
