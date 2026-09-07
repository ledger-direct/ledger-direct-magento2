# LedgerDirect - Magento2 Payment Plugin

[![CI](https://github.com/ledger-direct/ledger-direct-magento2/actions/workflows/ci.yml/badge.svg)](https://github.com/ledger-direct/ledger-direct-magento2/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
![Magento](https://img.shields.io/badge/Magento-2.4.7%20%7C%202.4.8-orange)
![PHP](https://img.shields.io/badge/PHP-8.2%20%7C%208.3%20%7C%208.4-777bb4)

LedgerDirect is a payment plugin for Magento2. Receive crypto and stablecoin payments directly – without middlemen,
intermediary wallets, extra servers or external payment providers. Maximum control, minimal detours!

Project Website: https://www.ledger-direct.com

GitHub: https://github.com/ledger-direct/ledger-direct-magento2

![Payment Page](payment_page.png)

## Compatibility
- Magento Open Source / Adobe Commerce **2.4.7** and **2.4.8**
- PHP **8.2**, **8.3**, or **8.4**
- [`hardcastle/ledger-direct-core`](https://packagist.org/packages/hardcastle/ledger-direct-core) — the
  platform-agnostic XRPL and pricing logic shared by all LedgerDirect plugins. Composer installs it
  together with the module.

## Available currencies:
- XRP (XRP Ledger)
- RLUSD (XRP Ledger)
- USDC (XRP Ledger)

### Install & setup instructions

##### 1. Run the below command to install the payment module from Composer
 ```
 composer require hardcastle/ledger-direct-magento2
 ```
 This also installs `hardcastle/ledger-direct-core` from Packagist. If you place the module under
 `app/code` instead, require the core at project level yourself:
 ```
 composer require hardcastle/ledger-direct-core:^0.4
 ```
##### 2. Run the below command to upgrade the payment module
 ```
 php bin/magento setup:upgrade
 ```
##### 3. Run the below command to re-compile the payment module
 ```
 php bin/magento setup:di:compile
 ```
##### 4. Run the below command to deploy static-content files like (images, CSS, templates and js files)
 ```
 php bin/magento setup:static-content:deploy -f
 ```
### 2. Configure the plugin
- Go to "Stores" > "Configuration" > "Sales" > "Payment Methods"
- Find "LedgerDirect" in the list of payment methods and click "Configure"
- Enter your Merchant Wallet Address (the address where you want to receive payments)
- Configure any additional settings as needed (e.g., which network to use (Testnet or Mainnet), which currencies to accept, etc.)

## Accepting Stablecoin Payments
- To accept stablecoin payments, enable the corresponding payment methods (RLUSD, USDC) under "Payment Methods"
- The merchant wallet address needs to have the corresponding trust lines set up for the stablecoins you want to accept

## Settlement
Once the payment is found on the ledger, the module checks the delivered amount against the quote (XRP within
0.15 %, stablecoins at least the quoted value from the quoted issuer), creates an offline-captured invoice, moves
the order to the method's *settled status* (`processing` by default) and sends the order and invoice emails —
the order confirmation is deliberately held back until then. This runs when the customer opens the payment page
and, for customers who close the tab after sending, from the `ledger_direct_settle_pending_orders` cron job every
five minutes, so Magento's cron must be running. An underpayment keeps the order pending and shows the outstanding
amount on the payment page. Note that Magento cancels orders left in `pending_payment` after
`Stores > Configuration > Sales > Orders Cron Settings > Pending Payment Order Lifetime` (480 minutes by default).

## Uninstall
`bin/magento module:uninstall Hardcastle_LedgerDirect` keeps the module's tables. Adding `--remove-data` drops
them, including `ledger_direct_xrpl_destination_tag` — the only record of which destination tags were already
issued. A reinstall then starts a fresh counter, and payments for orders that are still waiting can no longer be
matched. Only remove the data once no LedgerDirect order is open.

## External Services
LedgerDirect uses public APIs from Coingecko, Binance, and Kraken to retrieve current cryptocurrency exchange
rates. These rates are needed to correctly calculate and display payments. No personal or payment data is sent
to these services; only requests for current rates are made when a payment is processed or displayed. Rates are
cached briefly in Magento's cache so that a short outage of a rate source does not interrupt checkout.

- Coingecko API: [Terms of Service](https://www.coingecko.com/en/terms), [Privacy Policy](https://www.coingecko.com/en/privacy)
- Binance API: [Terms of Use](https://www.binance.com/en/terms), [Privacy Policy](https://www.binance.com/en/privacy)
- Kraken API: [Terms of Service](https://www.kraken.com/legal), [Privacy Policy](https://www.kraken.com/privacy)

## Development

The core library is developed alongside the plugins. To work against a local core checkout instead of the
released version, add a path repository to the *Magento project's* `composer.json` and require the branch:

```
composer config repositories.ledger-direct-core path ../LedgerDirectCorePHP/ledger-direct-core-php
composer require hardcastle/ledger-direct-core:dev-master
```

Keep the module's own constraint on the released version; the project-level override is a local concern.
