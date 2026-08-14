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
