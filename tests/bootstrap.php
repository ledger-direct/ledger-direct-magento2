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
