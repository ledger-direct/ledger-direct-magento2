<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Model\Config\Source;

use Hardcastle\LedgerDirect\Model\Config\Source\PendingEmailTemplate;
use Magento\Email\Model\ResourceModel\Template\Collection;
use Magento\Email\Model\ResourceModel\Template\CollectionFactory;
use Magento\Email\Model\Template\Config;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;

/**
 * The default option is the module's own template, whatever path the admin structure gives the field.
 */
class PendingEmailTemplateTest extends TestCase
{
    private const TEMPLATE_ID = 'payment_ledger_direct_pending_email_template';

    /**
     * @return iterable<string, array{string}>
     */
    public static function structurePaths(): iterable
    {
        // Magento_Paypal serves the payment section per merchant country, or as payment_other.
        yield 'plain' => ['payment/ledger_direct/pending_email_template'];
        yield 'merchant country US' => ['payment_us/ledger_direct/pending_email_template'];
        yield 'merchant country DE' => ['payment_de/ledger_direct/pending_email_template'];
        yield 'other countries' => ['payment_other/ledger_direct/pending_email_template'];
    }

    /**
     * @dataProvider structurePaths
     */
    public function testTheDefaultIsTheModulesTemplate(string $structurePath): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('toOptionArray')->willReturn([['value' => '7', 'label' => 'A custom template']]);
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $emailConfig = $this->createMock(Config::class);
        // Like Magento's: an id that email_templates.xml does not define throws.
        $emailConfig->method('getTemplateLabel')->willReturnCallback(
            function (string $id): string {
                if ($id !== self::TEMPLATE_ID) {
                    throw new \UnexpectedValueException("Email template '$id' is not defined.");
                }
                return 'LedgerDirect payment pending';
            }
        );

        $source = new PendingEmailTemplate($this->createMock(Registry::class), $factory, $emailConfig);
        $source->setPath($structurePath);
        $options = $source->toOptionArray();

        $this->assertSame(self::TEMPLATE_ID, $options[0]['value']);
        $this->assertSame('LedgerDirect payment pending (Default)', (string) $options[0]['label']);
        $this->assertSame('7', $options[1]['value']);
    }

    public function testTheTemplateIdMatchesEmailTemplatesXml(): void
    {
        $xml = (string) file_get_contents(dirname(__DIR__, 5) . '/etc/email_templates.xml');
        $this->assertStringContainsString('id="' . self::TEMPLATE_ID . '"', $xml);
    }
}
