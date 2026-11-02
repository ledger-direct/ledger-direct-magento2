# Changelog

## Unreleased

- Try shop: three hand-painted miniatures (2–4 EUR) on the home page and in a *Miniatures* category
  instead of the plain demo article; an existing try shop picks up a changed catalogue on its next
  start. Their pictures and texts are not under the MIT licence (`dev/try/catalog/NOTICE.md`)

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
