<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Model\Config\Backend;

use Hardcastle\LedgerDirect\Model\Config\Backend\AccentColor;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use PHPUnit\Framework\TestCase;

/**
 * Saving the accent colour: normalised when it carries white text, refused when it does not.
 */
class AccentColorTest extends TestCase
{
    private AccentColor $model;

    protected function setUp(): void
    {
        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(\Magento\Framework\Event\ManagerInterface::class));
        $this->model = new AccentColor(
            $context,
            $this->createMock(Registry::class),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TypeListInterface::class)
        );
    }

    public function testADarkColourIsNormalised(): void
    {
        $this->model->setValue(' #1F5EFF ');
        $this->model->beforeSave();

        self::assertSame('#1f5eff', $this->model->getValue());
    }

    public function testAnEmptyValueBecomesTheDefault(): void
    {
        $this->model->setValue('');
        $this->model->beforeSave();

        self::assertSame('#1f5eff', $this->model->getValue());
    }

    public function testALightColourIsRefused(): void
    {
        $this->model->setValue('#fff59d');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('too light');
        $this->model->beforeSave();
    }

    public function testSomethingThatIsNotAHexColourIsRefused(): void
    {
        $this->model->setValue('blue');

        $this->expectException(LocalizedException::class);
        $this->model->beforeSave();
    }
}
