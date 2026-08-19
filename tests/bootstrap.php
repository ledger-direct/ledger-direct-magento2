<?php
// Basic bootstrap for module-level PHPUnit tests.
// Assumes you run tests from the Magento project root or anywhere with Composer vendor available.

$root = __DIR__ . '/../../../../../'; // points to Magento src/ directory
$vendorAutoload = $root . 'vendor/autoload.php';

if (!file_exists($vendorAutoload)) {
    fwrite(STDERR, "Could not locate Composer autoload at: {$vendorAutoload}\n" .
        "Make sure you run composer install in the Magento project (src/vendor).\n");
    exit(1);
}

require_once $vendorAutoload;

// These tests mock some Magento classes (e.g. */Model/*Factory) that Magento normally
// generates on the fly under generated/code/ during a full app bootstrap. A plain
// `composer install` (no bin/magento setup:*) never triggers that, so on a fresh
// checkout (e.g. CI) such classes don't exist yet and class_exists()/Reflection-based
// mocking fails. Magento ships exactly this fix for unit tests that don't run a full
// app bootstrap: generate missing classes on demand into a scratch directory.
$generatorIo = new \Magento\Framework\Code\Generator\Io(
    new \Magento\Framework\Filesystem\Driver\File(),
    sys_get_temp_dir() . '/ledger-direct-generated-' . getmypid()
);
$generatedCodeAutoloader = new \Magento\Framework\TestFramework\Unit\Autoloader\GeneratedClassesAutoloader(
    [
        new \Magento\Framework\TestFramework\Unit\Autoloader\ExtensionAttributesGenerator(),
        new \Magento\Framework\TestFramework\Unit\Autoloader\ExtensionAttributesInterfaceGenerator(),
        new \Magento\Framework\TestFramework\Unit\Autoloader\FactoryGenerator(),
    ],
    $generatorIo
);
spl_autoload_register([$generatedCodeAutoloader, 'load']);
