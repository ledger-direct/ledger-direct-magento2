# Changelog

## Unreleased

- The module is on Packagist as `hardcastle/ledger-direct-magento2`; `composer.json` no longer carries a
  `version` field, the git tag is the version. No change for shops.

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

## Unreleased

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
