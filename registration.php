<?php

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Hardcastle_LedgerDirect', __DIR__);

/*
 * hardcastle/ledger-direct-core holds the pricing, XRPL and destination-tag logic this module
 * calls, and etc/di.xml configures its services (HTTP client, rate cache). setup:di:compile
 * only compiles argument configuration for classes it scans, and it scans registered
 * components only - so the library is registered the way magento/framework registers itself.
 * Without this, the di.xml arguments are silently dropped in compiled (production) mode and
 * the core's constructors fall back to their bare PSR interfaces. Composer records where the
 * package was installed, which covers both an app/code and a vendor installation of this module.
 */
if (class_exists(\Composer\InstalledVersions::class)
    && \Composer\InstalledVersions::isInstalled('hardcastle/ledger-direct-core')
) {
    $ledgerDirectCorePath = \Composer\InstalledVersions::getInstallPath('hardcastle/ledger-direct-core');

    if ($ledgerDirectCorePath !== null) {
        ComponentRegistrar::register(
            ComponentRegistrar::LIBRARY,
            'hardcastle/ledger-direct-core',
            $ledgerDirectCorePath . '/src'
        );
    }
}
