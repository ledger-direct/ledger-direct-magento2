<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Observer;

use Hardcastle\LedgerDirect\Core\Account\Assessment;
use Hardcastle\LedgerDirect\Core\Account\ReceivingAccountCheck;
use Hardcastle\LedgerDirect\Model\ChainServices;
use Hardcastle\LedgerDirect\Observer\ReceivingAccountAvailability;
use Hardcastle\LedgerDirect\Port\MagentoConfigProvider;
use LogicException;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * K3 (Documentation/Mainnet-Testing.md): a shop offered RLUSD to an account without a trustline,
 * and the customer's payment failed with tecPATH_DRY. The checkout leaves out a method whose
 * asset the receiving account cannot take; other methods, and methods Magento took out, pass.
 */
class ReceivingAccountAvailabilityTest extends TestCase
{
    /** @var list<string> */
    private array $asked = [];

    /** @var list<string> asset codes the check says no to */
    private array $denied = [];

    private bool $broken = false;

    public function testATokenTheAccountCannotTakeIsTakenOut(): void
    {
        $this->denied = ['RLUSD'];

        $this->assertFalse($this->isAvailable('xrpl_rlusd_payment'));
        $this->assertTrue($this->isAvailable('xrp_payment'));
        $this->assertSame(['XRPL:RLUSD:rAccount:testnet', 'XRPL:XRP:rAccount:testnet'], $this->asked);
    }

    public function testXahauIsAskedOnItsOwnChain(): void
    {
        $this->assertTrue($this->isAvailable('xahau_xah_payment'));
        $this->assertSame(['XAHAU:XAH:rAccount:testnet'], $this->asked);
    }

    public function testOtherMethodsAndMethodsAlreadyOutAreNotAsked(): void
    {
        $this->assertTrue($this->isAvailable('checkmo'));
        $this->assertFalse($this->isAvailable('xrpl_usdc_payment', false));
        $this->assertSame([], $this->asked);
    }

    public function testACheckThatBreaksTakesTokensOutAndKeepsTheNativeAsset(): void
    {
        $this->broken = true;

        $this->assertFalse($this->isAvailable('xrpl_usdc_payment'));
        $this->assertTrue($this->isAvailable('xrp_payment'));
    }

    private function isAvailable(string $code, bool $before = true): bool
    {
        $check = $this->check();
        $chains = new ChainServices([], [], [], [], ['XRPL' => $check, 'XAHAU' => $check]);
        $config = $this->createMock(MagentoConfigProvider::class);
        $config->method('getDestinationAccount')->willReturn('rAccount');
        $config->method('getNetwork')->willReturn('testnet');

        $result = new DataObject(['is_available' => $before]);
        $method = new DataObject(['code' => $code]);
        $observer = new Observer(['event' => new Event(['method_instance' => $method, 'result' => $result])]);

        (new ReceivingAccountAvailability($chains, $config, new NullLogger()))->execute($observer);

        return (bool) $result->getData('is_available');
    }

    private function check(): ReceivingAccountCheck
    {
        $test = $this;

        return new class ($test) implements ReceivingAccountCheck {
            /** @var ReceivingAccountAvailabilityTest */
            private ReceivingAccountAvailabilityTest $test;

            public function __construct(ReceivingAccountAvailabilityTest $test)
            {
                $this->test = $test;
            }

            public function offers(string $account, string $asset, string $network, ?string $amount = null): bool
            {
                return $this->test->answer($account, $asset, $network);
            }

            public function assess(string $account, string $network, array $assets, bool $fresh = true): Assessment
            {
                return new Assessment($account, $network, []);
            }
        };
    }

    /**
     * @internal for the stub above
     */
    public function answer(string $account, string $asset, string $network): bool
    {
        if ($this->broken) {
            throw new LogicException('a bug, not an unreachable node');
        }
        $chain = $asset === 'XAH' ? 'XAHAU' : 'XRPL';
        $this->asked[] = "{$chain}:{$asset}:{$account}:{$network}";

        return !in_array($asset, $this->denied, true);
    }
}
