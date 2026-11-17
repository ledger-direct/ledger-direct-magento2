<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Block\Adminhtml;

use Hardcastle\LedgerDirect\Block\Adminhtml\System\Config\ReceivingAccountCheck;
use Hardcastle\LedgerDirect\Core\Account\Assessment;
use Hardcastle\LedgerDirect\Core\Account\ReceivingAccountCheck as Check;
use Hardcastle\LedgerDirect\Helper\SystemConfig;
use Hardcastle\LedgerDirect\Model\ChainServices;
use Hardcastle\LedgerDirect\Model\PaymentMethods;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Escaper;
use PHPUnit\Framework\TestCase;

/**
 * The admin's receiving-account check on a shop set to mainnet: it assesses the mainnet
 * account on the mainnet — the branch the testnet runs never enter
 * (Documentation/Mainnet-Testing.md, K1).
 */
class ReceivingAccountCheckTest extends TestCase
{
    /** @var list<string> */
    private array $assessed = [];

    public function testOnMainnetTheMainnetAccountIsAssessedOnTheMainnet(): void
    {
        $html = $this->block('mainnet', 'rMainnetMerchant')->render($this->element('xrpl_account_check'));

        $this->assertSame(['rMainnetMerchant:mainnet:XRP,RLUSD,USDC'], $this->assessed);
        $this->assertStringContainsString('rMainnetMerchant on the mainnet', $html);
    }

    private function block(string $network, string $account): ReceivingAccountCheck
    {
        $config = $this->createMock(SystemConfig::class);
        $config->method('getDestinationAccount')->willReturn($account);
        $config->method('getNetwork')->willReturn($network);
        $config->method('isPaymentMethodActive')->willReturn(true);

        $test = $this;
        $check = new class ($test) implements Check {
            /** @var ReceivingAccountCheckTest */
            private ReceivingAccountCheckTest $test;

            public function __construct(ReceivingAccountCheckTest $test)
            {
                $this->test = $test;
            }

            public function offers(string $account, string $asset, string $network, ?string $amount = null): bool
            {
                return true;
            }

            public function assess(string $account, string $network, array $assets, bool $fresh = true): Assessment
            {
                $this->test->recordAssessment($account, $network, $assets);

                return new Assessment($account, $network, []);
            }
        };

        $escaper = $this->createMock(Escaper::class);
        $escaper->method('escapeHtml')->willReturnArgument(0);
        $escaper->method('escapeHtmlAttr')->willReturnArgument(0);

        // Magento's block constructor needs the whole backend context; the check needs only these.
        $block = (new \ReflectionClass(ReceivingAccountCheck::class))->newInstanceWithoutConstructor();
        $set = static function (string $class, string $property, $value) use ($block): void {
            (new \ReflectionProperty($class, $property))->setValue($block, $value);
        };
        $set(ReceivingAccountCheck::class, 'chains', new ChainServices([], [], [], [], ['XRPL' => $check]));
        $set(ReceivingAccountCheck::class, 'config', $config);
        $set(ReceivingAccountCheck::class, 'paymentMethods', new PaymentMethods());
        $set(ReceivingAccountCheck::class, 'escaper', $escaper);
        $set(\Magento\Framework\View\Element\AbstractBlock::class, '_request', $this->createMock(RequestInterface::class));

        return $block;
    }

    private function element(string $field): AbstractElement
    {
        // getFieldConfig() is a magic getter of the element's data: set as data, not mocked
        // (addMethods() is gone in PHPUnit 12, which Magento 2.4.9 ships).
        $element = $this->getMockBuilder(AbstractElement::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getHtmlId'])
            ->getMock();
        $element->setData('field_config', ['id' => $field]);
        $element->method('getHtmlId')->willReturn('payment_ledger_direct_' . $field);

        return $element;
    }

    /**
     * @internal for the stub above
     * @param list<string> $assets
     */
    public function recordAssessment(string $account, string $network, array $assets): void
    {
        $this->assessed[] = $account . ':' . $network . ':' . implode(',', $assets);
    }
}
