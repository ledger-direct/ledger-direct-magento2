// Whether the stablecoin walk may be skipped because of a testnet reset. Reads the shop's log on
// stdin and exits 0 ("skip, with a warning") only when all of this holds:
//   - the shop's setup could not set a trust line because the issuer does not exist
//     ("TrustSet to r… failed: tecNO_DST"), so it left RLUSD and USDC off;
//   - that issuer still does not exist on the testnet;
//   - the testnet is younger than seven days of ledgers (validated ledger index below 186 000,
//     about 3.24 s per ledger): a reset starts the index at 1, and the issuers of RLUSD and USDC
//     come back only when Ripple and Circle create them again.
// Anything else exits 1 and the walk runs, so it fails where it should: an issuer missing longer
// than a reset explains may have come back under another address, and only the issuer's own
// documentation says which. The same rule as the end-to-end harness (src/chains/xrpl/reset.ts).
//
//   docker compose logs --no-color <service> | node reset.mjs
const RPC = 'https://s.altnet.rippletest.net:51234';
const RESET_WINDOW_LEDGERS = 186_000;
const SECONDS_PER_LEDGER = 3.24;

const rpc = async (method, params) => {
  const response = await fetch(RPC, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ method, params: [params] }), signal: AbortSignal.timeout(20_000) });
  if (!response.ok) throw new Error(`${method}: HTTP ${response.status}`);
  return (await response.json()).result;
};

const walk = (why) => {
  console.log(`walking the stablecoin: ${why}`);
  process.exit(1);
};

let log = '';
for await (const chunk of process.stdin) log += chunk;

const issuers = [...new Set([...log.matchAll(/TrustSet to (r[1-9A-HJ-NP-Za-km-z]{24,34}) failed: tecNO_DST/g)].map((m) => m[1]))];
if (issuers.length === 0) walk('the shop did not report a missing issuer');

try {
  const ledgerIndex = Number((await rpc('ledger', { ledger_index: 'validated' })).ledger_index);
  const missing = [];
  for (const issuer of issuers) {
    const result = await rpc('account_info', { account: issuer, ledger_index: 'validated' });
    if (result.error === 'actNotFound') missing.push(issuer);
  }
  if (missing.length === 0) walk(`the issuer is back (${issuers.join(', ')}); restart the stack so the shop sets its trust lines`);
  const days = Math.round(((ledgerIndex * SECONDS_PER_LEDGER) / 86_400) * 10) / 10;
  if (!Number.isFinite(ledgerIndex) || ledgerIndex >= RESET_WINDOW_LEDGERS) {
    console.log(`::error title=Stablecoin issuer missing::${missing.join(', ')} not on the testnet about ${days} days (ledger ${ledgerIndex}) after it started again — longer than a reset explains; has the issuer come back under another address? (only from the issuer's own documentation)`);
    process.exit(1);
  }
  const line = `RLUSD and USDC not walked: the issuer ${missing.join(', ')} is not on the testnet, which started again about ${days} days ago (ledger ${ledgerIndex}); a reset, the issuer has not come back yet`;
  console.log(`warning: ${line}`);
  if (process.env.GITHUB_ACTIONS === 'true') console.log(`::warning title=Testnet reset::${line}`);
  process.exit(0);
} catch (error) {
  walk(`the testnet could not be asked (${error instanceof Error ? error.message : String(error)})`);
}
