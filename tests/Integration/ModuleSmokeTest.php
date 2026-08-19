<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration;

use Magento\Framework\Component\ComponentRegistrar;
use PHPUnit\Framework\TestCase;

class ModuleSmokeTest extends TestCase
{
    public function testModuleIsRegisteredWithMagentoComponentRegistrar(): void
    {
        if (!class_exists(ComponentRegistrar::class)) {
            $this->markTestSkipped('Magento framework is not available in the current environment.');
        }

        $registrar = new ComponentRegistrar();
        $paths = $registrar->getPaths(ComponentRegistrar::MODULE);

        $this->assertIsArray($paths, 'ComponentRegistrar did not return an array');
        $this->assertArrayHasKey(
            'Hardcastle_LedgerDirect',
            $paths,
            'Module Hardcastle_LedgerDirect is not registered. Ensure registration.php is autoloaded.'
        );
    }
}
