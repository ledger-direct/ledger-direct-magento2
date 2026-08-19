# LedgerDirect Tests

This directory contains starter Unit and lightweight Integration tests for the LedgerDirect Magento 2 module.

Structure:
- tests/phpunit.xml.dist – local PHPUnit configuration for this module
- tests/bootstrap.php – minimal bootstrap that loads Magento's Composer autoloader from src/vendor
- tests/Unit/** – pure PHPUnit unit tests using mocks (no Magento framework required)
- tests/Integration/** – smoke/integration tests that rely on Magento framework being present; tests will skip if not available

Running tests
1) Ensure Composer dependencies are installed in the Magento project under `src/`:

```
cd src
composer install
```

2) Run the tests from the module's `tests` directory:

```
cd app/code/Hardcastle/LedgerDirect/tests
../../../vendor/bin/phpunit -c phpunit.xml.dist
```

Notes
- Unit tests do not require a Magento database or application context.
- Integration tests included here are minimal and will `markTestSkipped` if Magento classes are not present. For full Magento integration testing (with DB), use Magento's official `dev/tests/integration` harness and provide an `install-config-mysql.php`.
- Expand tests by adding more cases under `tests/Unit` and `tests/Integration`. Keep constructor contracts simple to ease mocking.

## CI

`.github/workflows/ci.yml` runs three jobs on every push/PR:
- `lint` – `php -l` across all module sources.
- `coding-standard` – `phpcs --standard=Magento2` via `magento/magento-coding-standard`, which is public on packagist.org and needs no credentials.
- `unit-tests` – builds a real Magento 2.4.7 and 2.4.8 project (for real framework/interface classes to mock against) and runs this module's PHPUnit suite inside it.

The `unit-tests` matrix needs a `MAGENTO_COMPOSER_AUTH` repository secret with a free Magento Marketplace account's access keys, in the JSON form Composer expects:

```json
{"http-basic":{"repo.magento.com":{"username":"<public key>","password":"<private key>"}}}
```

Get the keys from marketplace.magento.com → My Profile → Access Keys, then add the secret under the repo's Settings → Secrets and variables → Actions. Without it, the `unit-tests` job logs a warning and skips its Magento-dependent steps instead of failing (so external contributors' fork PRs still get useful `lint`/`coding-standard` results).
