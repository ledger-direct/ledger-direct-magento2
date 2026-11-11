# Changelog

## 1.2.7

- The payment page shows its notices for a partial payment and for a payment in the wrong token
  as sentences again, in English and German; it showed their translation keys. A test checks that
  every key the module prints has a translation in every language file
- The payment page fills the window without a white border: it uses Magento's empty layout without
  the theme's stylesheets, so the browser's default body margin stayed

## 1.2.6

- Needs `hardcastle/ledger-direct-core` 0.11.2, which allows `brick/math` 1.0. A fresh Magento 2.4.9
  has brick/math 1.0, and installing the module there failed
- The payment page and the admin's payment information escape their output the way the Magento
  coding standard checks it, also where the value was escaped earlier. CI now sniffs the templates
  too, and adds the module to a Magento that was installed on its own first, as a shop does

## 1.2.5

- Installs with Composer on Magento 2.4.9: `symfony/console` may be 6.4 or 7, as 2.4.9 ships 7.4. The
  three `ledger-direct:*` commands run unchanged on both. CI now also resolves the module as a
  Composer package in each Magento release, which the unit tests under `app/code` do not
- The Adobe Commerce Marketplace package is named `ledger-direct/ledger-direct-magento2`, after the
  seller's vendor name, and replaces `hardcastle/ledger-direct-magento2`; Packagist keeps the
  `hardcastle` name. `bin/marketplace-zip.sh` writes the name into the zip
- A request for a new amount in the very second the quote expired kept the old quote, although the
  page already showed it as expired: the module counted a quote as expired one second later than
  the core does. Both now use the same boundary

## 1.2.4

- Magento 2.4.9 and PHP 8.5: `composer.json` allows PHP 8.5, which the Adobe Commerce Marketplace
  asks for when 2.4.9 is selected, and CI runs the unit tests on Magento 2.4.9 with PHP 8.5. The
  tests run on PHPUnit 9, 10 and 12, the versions Magento 2.4.7, 2.4.8 and 2.4.9 bring. No change
  to the module's code

## 1.2.3

- `etc/module.xml` carries the release's version again (`setup_version`, last set for 1.1.3), which
  the Adobe Commerce Marketplace checks against `composer.json`. No functional change; run
  `bin/magento setup:upgrade` after the update as usual
- The Marketplace user guide (`docs/marketplace/user-guide.pdf`) is rendered from the current README

## 1.2.2

- Needs `hardcastle/ledger-direct-core` 0.11.1
- When the price oracles disagree, there is no price, instead of a stale rate. The payment page then
  says the payment details are temporarily unavailable and offers to try again; nothing is stored.
  If the new amount for an expired quote cannot be priced, the quote stays as it was and the page
  says to try again
- After a testnet reset, such as the XRP Ledger testnet's on 2026-11-25, only the first sync of each
  receiving account sends a failing request, as long as the shop's cache keeps the note of the reset;
  before, every sync did. If that cache entry is cleared or evicted, or the account goes 30 days
  without a sync, the next sync sends one more failing request

## 1.2.1

- An order is settled exactly once. Overlapping checks of the same order could create a second invoice
  and repeat the invoice email

## 1.2.0

- Xahau: XAH on the [Xahau](https://xahau.network) network, a chain of its own that speaks the XRP
  Ledger's protocol, paid by destination tag. A configuration group *Ledger Direct / Xahau* (testnet
  switch, receiving account per network) and the payment method *Ledger Direct – XAH (Xahau)*, off by
  default. The payment request behind the QR code names the Xahau network, so Xaman offers to switch if
  it is set to another one; the browser-wallet buttons stay off on a Xahau page. Xahau has its own
  tables (`ledger_direct_xahau_tx`, `ledger_direct_xahau_destination_tag`, created by `setup:upgrade`)
  and shares nothing with the XRP Ledger, also for an account that exists on both. The cron job syncs
  the configured account of each chain. Saving a Xahau account that is also the XRP Ledger's account on the
  same network shows a warning: the same address is a different account on each chain. Needs
  `hardcastle/ledger-direct-core` 0.11
- `ledger-direct:xrpl-account:lookup` and `ledger-direct:xrpl-transaction:lookup` take `--chain XAHAU`;
  `ledger-direct:xrp-price:lookup --asset XAH` quotes XAH
- The payment methods come from the core's chain catalogue: one method per chain and asset, and a test
  that turns red when the core adds a chain the module has not decided on
- `e2e.yml` runs the case catalogue on Xahau too, after the XRP Ledger, from a CI treasury of its own
  (`LEDGERDIRECT_CI_XAHAU_TREASURY_SEED` / `_ADDRESS`)

## 1.1.6

- The admin page *Payment Methods* renders again in the default and website scope. Since the payment
  instructions email (1.1.0) the template field looked for a default template named after the
  section Magento_Paypal serves for the merchant country (`payment_us_…`, `payment_other_…`), which
  does not exist, and the whole page failed: no payment method could be configured in the admin
- XRP has its own group in the admin, *Ledger Direct – XRP (XRPL)*, with *Enabled* and *Title*, like
  RLUSD and USDC. The *Enabled* switch in *Ledger Direct / XRPL* is gone: it saved a setting nothing
  read, so switching it off left XRP in the checkout. Check the new switch after the update; it shows
  whether XRP is really on
- The checkout shows the title set in the admin for RLUSD and USDC too; it showed a fixed text instead

## 1.1.5

- Try shop: three hand-painted miniatures (2–4 EUR) on the home page and in a *Miniatures* category
  instead of the plain demo article; on its next start an existing try shop takes over new articles
  and changed names, texts and prices from the catalogue (changed pictures, URL keys and removed
  articles need `docker compose down -v`). Their pictures and texts are not under the MIT licence
  (`dev/try/catalog/NOTICE.md`)

## 1.1.4

- Try it in five minutes: `dev/try` starts a complete Magento shop with the module on the XRP Ledger
  testnet (`docker compose up -d --wait`): a home page that explains the demo and offers a demo article
  for 1 EUR, the shop's own testnet wallet with trust lines for RLUSD and USDC, and a demo admin login
  — no Magento access keys, Magento Open Source comes from Mage-OS's public mirror. A CI job builds it
  on every pull request, installs the module into a fresh Magento on Linux the way a merchant does,
  smoke-tests the payment page and the status endpoint and walks the storefront in a real browser,
  from the home page to the payment page, with XRP, RLUSD and USDC
- The payment page works on Linux. `etc/di.xml` named the payment-details service
  `Model\Api\XrpPaymentService`, while the class lives in `Model/API`; file systems that ignore case
  (macOS, Windows) resolved it, a Linux server — where shops run — answered every payment page with a
  500 ("Class … does not exist"). Affected: every release up to 1.1.3 on a case-sensitive file system. A new
  unit test resolves every class the XML configuration names under its exact name, so CI on Linux
  catches this
- `bin/magento setup:db:status` reports the module up to date after `setup:upgrade`. Ten
  columns of the two ledger tables were declared with an empty comment, which Magento's declarative
  schema read as a difference on every run: the status never settled, and every `setup:upgrade`
  rewrote both tables — a deploy pipeline that decides on maintenance mode by that status would
  enter it on every deploy. The columns carry real comments now; no data changes

## 1.1.3

- Browser wallets work again on the payment page. Every Magento page has RequireJS, and the UMD
  wrappers of libraries bundled into the payment page package registered with it instead of
  exporting to CommonJS; the wallet module failed with `default is not a function` the moment the
  customer opened the wallet list and reported "no browser wallet found" with Crossmark or GemWallet
  installed — since 1.1.0, when the package replaced the module's own scripts. Payment page package
  0.3.1 builds without the AMD branch (`view/frontend/web/js/ledger-direct-payment-ui/`, `VERSION`
  0.3.1); the stylesheet is unchanged
- The package's `headers` option for the poll (0.3.0) comes along; this module's page does not need it

## 1.1.2

- Core 0.9: the synced transactions store the CTID in the column `tx_ctid` instead of `ctid`, the name every
  LedgerDirect plugin uses from now on (PostgreSQL reserves `ctid` for a system column). The declarative schema
  renames the column on `setup:upgrade` and carries the data over; nothing changes for customers or merchants.
- Core 0.9: a transaction whose CTID is malformed is skipped during sync, and logged, instead of being stored
  truncated.

## 1.1.1

- The module is on Packagist as `hardcastle/ledger-direct-magento2`; `composer.json` no longer carries a
  `version` field, the git tag is the version. No change for shops.
- Core 0.8.1: a payment in the other asset class — a token sent for an XRP order, or XRP for a token order —
  is reported as a wrong-token payment with the full amount still due (order status *XRPL payment incomplete*),
  instead of being ignored. Before, the page stayed on "waiting" while the money sat on the ledger.

## 1.1.0

- The payment page is redesigned on `@ledger-direct/payment-ui` (0.1.1), the package every LedgerDirect plugin
  shares: the amount to send is the largest thing on the page with a copy button, the receiving account, the
  destination tag (marked as required) and, for tokens, the issuer are numbered fields with copy buttons, a
  countdown with a bar, one column on phones, dark mode follows the system.
- One QR code with the receiving account, the destination tag and the amount (for tokens also currency and
  issuer), the payment request the core specifies (`PaymentUri`, core 0.8); a server-rendered code stays for
  browsers without JavaScript.
- Browser wallets over XRPL Connect: Crossmark, GemWallet, MetaMask Snap, Ledger, Otsu and Xyra when detected;
  Xaman and WalletConnect when the merchant enters their public identifier in the configuration. The wallet
  library is loaded only when the wallet list is opened.
- New configuration: the logo of the payment page (store logo, an uploaded picture, or a monogram), an accent
  colour (refused when too light for white text), the Xaman API key and the WalletConnect project id.
- The "Check payment now" button is a form that reloads the page, which syncs — it works without JavaScript now.
- Icons for XRP, RLUSD and USDC next to the payment methods in the checkout.
- Requires `hardcastle/ledger-direct-core` ^0.8 and `bacon/bacon-qr-code`.
- Payment instructions email right after the order is placed, with the key link to the payment page (configurable:
  enabled, sender identity, template). The order confirmation is still held back until the payment has arrived.
- Payment information block for LedgerDirect orders (admin order view, customer order view, order emails): quote,
  payment status, received and outstanding amounts, transaction hash with explorer link.

## 1.0.0

- Payment status contract: the payment page shows one of five states (waiting, expired, partial, wrong_asset,
  settled), polls the new status endpoint `ledger-direct/payment/status` every eight seconds and leaves via
  `redirect` as soon as the order no longer waits for payment.
- Guest orders: the payment page and the status endpoint are opened by the order's key (`protect_code`) instead of a
  login; the URL after the checkout carries it.
- Partial payments add up and a top-up of the shortfall settles the order; a payment in the wrong asset is displaced
  by the right one (previously the first hit locked the order).
- New order status *XRPL payment incomplete* under `pending_payment` for a shortfall or a wrong-asset payment, spared
  by Magento's pending-payment cleanup.
- Expired quotes are shown as such, with a refresh action that keeps account and tag; the page no longer re-quotes
  silently on reload.
- The ledger is synced at most once per five seconds and receiving account from the storefront; the cron syncs each
  receiving account once per run instead of once per order.
- The anonymous REST routes `/V1/ledger-direct/payment/price/:orderId` and `/V1/ledger-direct/payment/xrp-payment/:orderNumber`
  are removed.
- Requires `hardcastle/ledger-direct-core` ^0.7.
