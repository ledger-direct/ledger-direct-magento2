<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Gateway\Config;

use Hardcastle\LedgerDirect\Gateway\Config\TitleValueHandler;
use Magento\Framework\Phrase;
use Magento\Framework\Phrase\RendererInterface;
use Magento\Payment\Gateway\ConfigInterface;
use PHPUnit\Framework\TestCase;

/**
 * The default title goes through the translation, a title the merchant set does not.
 */
class TitleValueHandlerTest extends TestCase
{
    /**
     * @var RendererInterface
     */
    private RendererInterface $previousRenderer;

    protected function setUp(): void
    {
        $this->previousRenderer = Phrase::getRenderer();
        $renderer = $this->createMock(RendererInterface::class);
        $renderer->method('render')->willReturnCallback(
            static fn(array $source): string => 'translated: ' . end($source)
        );
        Phrase::setRenderer($renderer);
    }

    protected function tearDown(): void
    {
        Phrase::setRenderer($this->previousRenderer);
    }

    private function handler(?string $configured, int $storeId = 3): TitleValueHandler
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->expects($this->once())->method('getValue')->with('title', $storeId)->willReturn($configured);

        return new TitleValueHandler($config);
    }

    public function testTheDefaultTitleIsTranslated(): void
    {
        $this->assertSame(
            'translated: Pay with RLUSD | Ledger Direct',
            $this->handler('Pay with RLUSD | Ledger Direct')->handle(['field' => 'title'], 3)
        );
    }

    public function testATitleOfTheMerchantStaysAsItIs(): void
    {
        $this->assertSame('Mit XRP zahlen', $this->handler('Mit XRP zahlen')->handle(['field' => 'title'], 3));
    }

    public function testNoTitleStaysNoTitle(): void
    {
        $this->assertNull($this->handler(null)->handle(['field' => 'title'], 3));
    }
}
