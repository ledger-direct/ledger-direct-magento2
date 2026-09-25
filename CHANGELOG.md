# Changelog

## Unreleased

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
