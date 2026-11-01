<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every class the module's XML configuration names must exist under exactly that name.
 *
 * A reference whose case differs from the file (Model\Api vs Model/API) resolves on macOS and
 * Windows, whose file systems ignore case, and fails on Linux — where shops run. The payment page
 * of 1.1.3 answered every request with a 500 on Linux for exactly that reason, while the dev shop
 * and the unit tests, which build the class by its correct name, never noticed. CI runs on Linux,
 * so this test catches it there.
 */
class DiConfigurationTest extends TestCase
{
    private const MODULE_PREFIX = 'Hardcastle\\LedgerDirect\\';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function moduleClassReferences(): iterable
    {
        $etc = dirname(__DIR__, 2) . '/etc';
        $files = array_merge(glob($etc . '/*.xml') ?: [], glob($etc . '/*/*.xml') ?: []);
        $virtualTypes = [];
        $references = [];

        foreach ($files as $file) {
            $xml = (string) file_get_contents($file);

            preg_match_all('/<virtualType\s+name="([^"]+)"/', $xml, $virtual);
            foreach ($virtual[1] as $name) {
                $virtualTypes[$name] = true;
            }

            // type="…" / instance="…" attributes, and class names given as element text
            preg_match_all('/(?:type|instance|class)="(Hardcastle\\\\LedgerDirect\\\\[^"]+)"/', $xml, $attributes);
            preg_match_all('/>\s*(Hardcastle\\\\LedgerDirect\\\\[A-Za-z0-9_\\\\]+)\s*</', $xml, $texts);

            foreach (array_merge($attributes[1], $texts[1]) as $class) {
                $references[$class] = basename(dirname($file)) . '/' . basename($file);
            }
        }

        foreach ($references as $class => $file) {
            if (isset($virtualTypes[$class]) || str_starts_with($class, self::MODULE_PREFIX . 'Core\\')) {
                continue;
            }
            yield $class => [$class, $file];
        }
    }

    /**
     * @dataProvider moduleClassReferences
     */
    public function testTheReferencedClassExistsUnderExactlyThisName(string $class, string $file): void
    {
        $this->assertTrue(
            class_exists($class) || interface_exists($class),
            sprintf('%s names %s, which does not exist (check the case of every namespace segment)', $file, $class)
        );

        $declared = (new \ReflectionClass($class))->getName();
        $this->assertSame($class, $declared, sprintf('%s names %s, but the class is declared as %s', $file, $class, $declared));
    }
}
