# Manual test cases — payment status, Magento

The Magento instantiation of the core's case catalogue, `docs/manual-tests/payment-status.md` in
`hardcastle/ledger-direct-core`. The catalogue says *what* must hold on every platform; this file says how to
make it happen in this shop: which setting, which command, which status name, where the evidence shows up.
Same IDs.

**How this is used.** A pull request that touches the payment page, `Controller/Payment/Status`,
`OrderPaymentService`, `OrderSettlementService` or the cron lists the applicable IDs under
"Manual end-to-end tests" as checkboxes and ticks what was actually run, with the order number or hash next
to it. Results are not collected in this file; the PR is the record. Should a case get automated or become a
merge gate one day, it keeps its ID.

## Environment

- The markshust/docker-magento stack; commands below run through its wrappers (`bin/magento`, `bin/cli`,
  `bin/mysql`) from the project root.
- **Own testnet wallet** for this shop (never the one another shop uses — shared tag space; never an issuer
  account): `tests/manual/xrpl-e2e/faucet.js` funds one, then trust lines to the testnet issuers of RLUSD and
  USDC from the core's `StablecoinRegistry`. Seeds stay in the git-ignored `tests/manual/`, never in docs or
  commits. `tests/manual/xrpl-e2e/pay.js <seed> <destination> <tag> <amount>` sends a signed payment and waits
  for validation.
- Configuration: `bin/magento config:set payment/ledger_direct/use_testnet 1`,
  `payment/ledger_direct/xrpl_testnet_account <wallet>`, `payment/xrpl_rlusd_payment/active 1`,
  `payment/xrpl_usdc_payment/active 1`, `payment/ledger_direct/quote_expiry 300` unless a case says
  otherwise; `bin/magento cache:clean config` afterwards.
- The payment page: `/ledger-direct/payment/index?id=<entity_id>&key=<protect_code>`. The poll it makes:
  `/ledger-direct/payment/status?id=<entity_id>&key=<protect_code>`. Watch it in the browser's network tab, or
  call it with `curl -s -H 'Accept: application/json'`. The key of an order:
  `bin/mysql -e "SELECT entity_id, increment_id, protect_code FROM sales_order ORDER BY entity_id DESC LIMIT 1"`.
- Order state and status: Sales → Orders (the *Status* column), or

  ```sql
  SELECT entity_id, increment_id, state, status, total_paid FROM sales_order WHERE increment_id = '000000031';
  ```

  The status history: the order's *Comments History* tab, or `sales_order_status_history` by `parent_id`.
  An order waits for payment while its state is `pending_payment` (status `pending_payment` or
  `ledger_direct_payment_incomplete`); `processing` with an invoice means paid.
- The cron: `bin/magento cron:run --group default` twice (Magento schedules in the first run and executes in
  the second), or the git-ignored `bin/cli php app/code/Hardcastle/LedgerDirect/tests/manual/run_cron.php`,
  which calls the job directly. Its summary is an info line in `var/log/system.log`
  (`bin/cli tail -n 20 var/log/system.log`).

## Automated with ld-e2e

The `ledger-direct-e2e` harness runs PS-01 to PS-09 and PS-11 against this shop unattended (PS-10 waits
35 minutes and belongs to a nightly run). It pays real testnet transactions from its treasury to a receiving
account it creates for the run, and writes the checklist lines into the pull request:

```
ld-e2e run --target magento --base-url https://localhost:8444 \
  --compose-dir /path/to/docker-magento --cases automated
ld-e2e report pr --repo ledger-direct/ledger-direct-magento2 --pr <n>
```

What it does, in this shop's terms — the same steps as above, without a browser:

- **Orders** go through the REST API as a headless storefront places them: `POST /rest/V1/guest-carts`, the
  1.00 test article `LD-E2E-001` (created through the admin REST API on first use, then
  `indexer:reindex` — the dev stack indexes by schedule and has no cron, so a new article is "not
  available" until then), addresses and the cheapest shipping method, `payment-information` with
  `xrp_payment`, `xrpl_rlusd_payment` or `xrpl_usdc_payment`. The `protect_code` comes from
  `GET /rest/V1/orders/<id>` with an admin token.
- **Configuration**, the **cron job** and the **throttle mark** have no REST face: a PHP script on stdin in the
  `phpfpm` container (`bin/docker-compose exec -T phpfpm php --`) writes the `payment/ledger_direct/*` and
  `payment/*/active` paths and cleans the config cache, runs `Cron\SettlePendingOrders::execute()`, and
  reads the mark from `Model\Cache\RateCache`.
- **The customer's side** is HTTP: the page (state, displayed amount, account, tag), the status endpoint, and
  the refresh as a POST to `ledger-direct/payment/refresh` with the `form_key` the page rendered (and the
  page's cookies, if it set any).
- **Small orders:** the driver picks the cheapest shipping method, so enable free shipping in the dev shop
  (`bin/magento config:set carriers/freeshipping/active 1`); with Flat Rate alone every order is 6.00 instead
  of 1.00, and the wrong-asset case (PS-04) pays that twice in stablecoins.
- **What counts as the payment record:** the settling hash is the invoice's `transaction_id`; a partial or
  wrong-asset hit is noted in `last_trans_id` and the status history. The harness reads the invoices.
- **PS-08** reads the core's throttle mark (`ledger-direct.sync.v1.testnet.<account>` in Magento's cache, the
  time of the last sync): one status call inside the window must not change it.
- **PS-09** checks the summary line the cron logs (`accounts_synced`, `checked`, `settled`) in
  `var/log/system.log` after the run.
- **PS-11** cancels the order with `POST /rest/V1/orders/<id>/cancel`.
- The dev stack's certificate is self-signed; the driver accepts it for `localhost`.

## PS-01 — Waiting

Place an order with *XRP*, send nothing, open the payment page.

Look for: `data-ld-state="waiting"` on the page wrapper; the countdown next to *This amount is guaranteed
for*; a request to `/ledger-direct/payment/status?…` every 8 s in the network tab, each answering
`"state":"waiting"` with a falling `seconds_left` and no `redirect`; the order `pending_payment`.

## PS-02 — Expired, then refreshed

`quote_expiry` 60 in the configuration. Place an XRP order, send nothing, wait a minute with the page open.

Look for: the countdown block hidden, the expired notice with *Get an updated amount* visible; the poll answers
`"state":"expired"`, `"seconds_left":null`, still no `redirect`. Click the button (a
`POST /ledger-direct/payment/refresh` with the form key): the page reloads with a new amount and a fresh
countdown, the destination tag unchanged (`additional_data` of the `sales_order_payment` row). Reset the
validity afterwards.

Also: the button is refused (plain redirect back, no new quote) when something has arrived — try it on the
PS-03 order.

## PS-03 — Partial, then topped up

Place a small XRP order. Send half the displayed amount to account and tag. Do **not** reload.

Look for, within 8 s: the `data-ld-partial` block visible with *X XRP received so far. Y XRP is still
outstanding*; the poll answers `"state":"partial"` with `amount_paid` and `shortfall`; the order's status
**XRPL payment incomplete** in the grid while the page is still open, with a history line naming the amounts
and the hash. Then send `Y` to the same account and tag: the poll answers `redirect`, the page leaves, the
order is `processing` with **exactly one** invoice (`sales_invoice` by `order_id`), `total_paid` equals
`grand_total`; in `sales_order_payment.additional_data` → `xrpl.hash` is the second transaction's hash and
`amount_paid` the sum.

## PS-04 — Wrong asset, then the right one

Place a *USDC* order. Pay the full amount in **RLUSD** to account and tag.

Look for: the `data-ld-wrong-asset` block visible naming the RLUSD amount and the full USDC request; the poll
answers `"state":"wrong_asset"` with `amount_paid.issuer` the RLUSD issuer and `shortfall` in USDC with the
full value; the order *XRPL payment incomplete* with a "not in the requested USDC" history line. Then send the
USDC: `redirect`, `processing`, and `xrpl.hash` is the USDC transaction.

Run it a second time **across the asset class**: an *XRP* order paid with RLUSD. Look for the same wrong-asset
block; the poll's `amount_paid` is the token object and `shortfall` the XRP number; the order *XRPL payment
incomplete*; then the XRP in full settles with the XRP hash. Core 0.8.1 — before, the payment was skipped and the
page stayed on *waiting*.

## PS-05 — Settled

Place an XRP order of about 1.00 in shop currency. Send exactly the amount the page shows.

Look for: `redirect` in the next poll, the order confirmation, the order `processing`; exactly **one** invoice,
one order confirmation and one invoice mail (mailcatcher on port 1080), and in the status history exactly one
"XRPL payment settled" line (not one from the status endpoint and another from the page or the cron).

## PS-06 — Guest, key knowledge instead of login

Place the order as a guest (guest checkout, or `POST /rest/V1/guest-carts/…`). After the checkout the
address bar reads `/ledger-direct/payment/index?id=…&key=…`; copy that URL. Open it in a private window.

Look for: the payment page renders without a login prompt; the poll URL opened in the same window answers
200 with the payload.

## PS-07 — Wrong key is refused without a hint

```
curl -s -o /dev/null -w '%{http_code}\n' 'https://magento.test/ledger-direct/payment/status?id=<entity_id>&key=wrong'
curl -s -o /dev/null -w '%{http_code}\n' 'https://magento.test/ledger-direct/payment/status?id=999999&key=wrong'
```

Look for: 403 both times, the same body `{"error":"forbidden"}`. The payment page with a wrong key redirects
to `sales/guest/form` (or `sales/order/history` for a logged-in customer) in both cases too. Another
customer's session does not open it either. The former REST routes
`/rest/V1/ledger-direct/payment/xrp-payment/<order number>` and `/rest/V1/ledger-direct/payment/price/<id>`
answer 404.

## PS-08 — Throttling

Two open orders on the testnet wallet, two payment pages open in two browsers (or one page plus `curl` on
the poll URL twice within 5 s).

Look for: both polls answer the full payload. The node request count: the core does not log each
`account_tx` call, so measure it either with the cron's summary (PS-09, `accounts_synced`) or, for the page,
by timing — a poll that synced takes noticeably longer (about a second) than one answered from the stored
intent; only one of two calls within 5 s does.

## PS-09 — Safety net without a browser

Place an XRP order, close the page, send the full amount. Then run the cron (see Environment).

Look for: the order `processing` without any page having been open, and the line

```
main.INFO: LedgerDirect: scheduled settlement run {"accounts_synced":1,"checked":N,"settled":1}
```

`accounts_synced` is the number of `account_tx` requests the run made: one per distinct account and network,
whatever `checked` is. With one order placed on testnet and one on mainnet (switch the configuration in
between) it reads `2`. An order from before the core retrofit ("Missing schema_version") is logged as an
error and skipped every run; that is expected.

## PS-10 — Late return after the checkout session is gone

Magento has no payment token; what expires is the checkout session the order confirmation page depends on.
Place an order, open the key URL in a **new private window** (no checkout session), pay the full amount
there.

Look for: the poll answers `"state":"settled"` with a `redirect` to `/sales/guest/view` (a guest) or
`/sales/order/view/order_id/<id>` (a logged-in customer) instead of `checkout/onepage/success`; following
it shows the order, no cart redirect; the order `processing`.

## PS-11 — Closed by the merchant

Place an XRP order, send nothing, keep the page open. In the admin, cancel the order.

Look for: the next poll carries a `redirect` while `state` is still `waiting`; the page leaves. Then send the
amount anyway and run the cron: the order stays `canceled` — neither the cron (which only looks at
`pending_payment` orders) nor the page or the poll (which no longer sync an order that does not wait) touch it;
the payment stays in the transaction table for the merchant to deal with by hand.

## PW-01 — Browser wallet

Open the payment page in a desktop browser with Crossmark or GemWallet installed, on the testnet, with a funded
account. Click "Pay with a browser wallet", pick the wallet, confirm the transaction there. Do it once for an XRP
order and once for a token order (RLUSD or USDC — the wallet's account needs a trust line to the testnet issuer).

Look for: the wallet shows the receiving account, the destination tag and the amount exactly as the page shows
them (the token order names currency and issuer); after signing, the page says "Sent – we are checking for it"
and the next poll settles the order, then the success view and the redirect. The order `processing` with the
transaction hash on the invoice.

## PW-02 — Wallet on the wrong network

The same, but with the wallet set to the mainnet while the shop is on the testnet.

Look for: no transaction is signed; the page says the wallet is set to another network and names the one to
switch to. (Crossmark switches to the requested network at sign-in on its own — then the case passes as PW-01.)

## PW-03 — Phone

Open the page at 390 px width (device emulation is enough), once without a Xaman API key or WalletConnect
project id configured, once with one of them.

Look for: one column, the QR code collapsed behind "Show QR code", the amount still the largest thing on the
page; without identifiers there is no wallet section at all, with one there is a single "Open in wallet app"
button and no browser-wallet list.

## PW-04 — Scanning the QR code

Scan the page's QR code with Xaman on the testnet, for an XRP order and for a token order.

Look for: Xaman takes over the receiving account, the destination tag and the amount as the page shows them —
the amount as the XRP decimal, not as drops — and, for a token, currency and issuer; nothing to type. Send, and
the page settles the order.
