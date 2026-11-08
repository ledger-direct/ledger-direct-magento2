<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Model\Config\Backend;

use Hardcastle\LedgerDirect\Model\Config\Backend\XahauAccount;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The same r-address is a different account on each chain; the merchant is warned, not stopped,
 * when the Xahau account on a network is the XRP Ledger's (INVARIANTS.md, "Xahau").
 */
class XahauAccountTest extends TestCase
{
    /** @var ManagerInterface|MockObject */
    private $messages;

    private XahauAccount $model;

    protected function setUp(): void
    {
        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(\Magento\Framework\Event\ManagerInterface::class));
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(fn (string $path) => match ($path) {
            'payment/ledger_direct/xrpl_testnet_account' => 'rSameAddress',
            'payment/ledger_direct/xrpl_mainnet_account' => 'rMainnetOnly',
            default => null,
        });
        $this->messages = $this->createMock(ManagerInterface::class);
        $this->model = new XahauAccount(
            $context,
            $this->createMock(Registry::class),
            $scopeConfig,
            $this->createMock(TypeListInterface::class),
            $this->messages
        );
    }

    public function testTheSameAccountAsTheXrpLedgersOnTheNetworkIsAWarningNotARefusal(): void
    {
        $this->messages->expects($this->once())->method('addWarningMessage')
            ->with($this->stringContains('rSameAddress'));
        $this->model->setPath('payment/ledger_direct_xahau/xahau_testnet_account');
        $this->model->setValue(' rSameAddress ');

        $this->model->beforeSave();

        self::assertSame('rSameAddress', $this->model->getValue(), 'trimmed and kept');
    }

    public function testADifferentAccountOrAnotherNetworkPassesQuietly(): void
    {
        $this->messages->expects($this->never())->method('addWarningMessage');

        $this->model->setPath('payment/ledger_direct_xahau/xahau_mainnet_account');
        $this->model->setValue('rSameAddress');
        $this->model->beforeSave();

        $this->model->setPath('payment/ledger_direct_xahau/xahau_testnet_account');
        $this->model->setValue('');
        $this->model->beforeSave();
    }
}
