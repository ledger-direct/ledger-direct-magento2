<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Service;

use Hardcastle\LedgerDirect\Helper\SystemConfig;
use Hardcastle\LedgerDirect\Service\XrplClientService;
use Magento\Framework\HTTP\Client\Curl;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class XrplClientServiceTest extends TestCase
{
    /** @var SystemConfig|MockObject */
    private $configHelper;

    /** @var Curl|MockObject */
    private $curl;

    private XrplClientService $service;

    protected function setUp(): void
    {
        $this->configHelper = $this->createMock(SystemConfig::class);
        $this->curl = $this->createMock(Curl::class);
        $this->service = new XrplClientService($this->configHelper, $this->curl);
    }

    public function testFetchTransactionReturnsResultOnSuccess()
    {
        $this->configHelper->method('isTest')->willReturn(true);
        $this->curl->expects($this->once())
            ->method('post')
            ->with('https://s.altnet.rippletest.net:51234/', $this->anything());
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode([
            'result' => ['hash' => 'ABC123', 'validated' => true],
        ]));

        $result = $this->service->fetchTransaction('ABC123');

        $this->assertSame(['hash' => 'ABC123', 'validated' => true], $result);
    }

    public function testFetchTransactionReturnsEmptyArrayOnNonSuccessStatus()
    {
        $this->configHelper->method('isTest')->willReturn(true);
        $this->curl->method('getStatus')->willReturn(500);
        $this->curl->method('getBody')->willReturn('{}');

        $result = $this->service->fetchTransaction('ABC123');

        $this->assertSame([], $result);
    }

    public function testFetchTransactionReturnsEmptyArrayWhenCurlThrows()
    {
        $this->configHelper->method('isTest')->willReturn(true);
        $this->curl->method('post')->willThrowException(new \Exception('network error'));

        $result = $this->service->fetchTransaction('ABC123');

        $this->assertSame([], $result);
    }

    public function testFetchTransactionReturnsEmptyArrayOnJsonRpcErrorStatus()
    {
        $this->configHelper->method('isTest')->willReturn(true);
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode([
            'result' => ['status' => 'error', 'error' => 'txnNotFound'],
        ]));

        $result = $this->service->fetchTransaction('ABC123');

        $this->assertSame([], $result);
    }

    public function testFetchAccountTransactionsReturnsTransactionsOnSuccess()
    {
        $this->configHelper->method('isTest')->willReturn(false);
        $this->curl->expects($this->once())
            ->method('post')
            ->with('https://xrplcluster.com/', $this->anything());
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode([
            'result' => ['transactions' => [['tx' => ['hash' => 'H1']]]],
        ]));

        $result = $this->service->fetchAccountTransactions('rAddress');

        $this->assertSame([['tx' => ['hash' => 'H1']]], $result);
    }

    public function testFetchAccountTransactionsReturnsEmptyArrayWhenResultHasNoTransactionsKey()
    {
        $this->configHelper->method('isTest')->willReturn(true);
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode([
            'result' => ['account' => 'rAddress'],
        ]));

        $result = $this->service->fetchAccountTransactions('rAddress');

        $this->assertSame([], $result);
    }

    public function testFetchAccountTransactionsReturnsEmptyArrayWhenCurlThrows()
    {
        $this->configHelper->method('isTest')->willReturn(true);
        $this->curl->method('post')->willThrowException(new \Exception('network error'));

        $result = $this->service->fetchAccountTransactions('rAddress');

        $this->assertSame([], $result);
    }

    public function testFetchAccountTransactionsReturnsEmptyArrayOnInvalidJsonBody()
    {
        $this->configHelper->method('isTest')->willReturn(true);
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('not json');

        $result = $this->service->fetchAccountTransactions('rAddress');

        $this->assertSame([], $result);
    }
}
