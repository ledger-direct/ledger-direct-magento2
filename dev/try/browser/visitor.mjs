// Walks the try shop in a real browser the way a prospect does: home page, add to cart, guest
// checkout, a LedgerDirect payment method, place order, payment page in state "waiting". A screenshot
// of every step goes to the output folder; exits non-zero on the first failure.
//
//   npm install
//   CHROME=/path/to/chrome node visitor.mjs [base url] [screenshot dir] [xrp_payment|xrpl_rlusd_payment|xrpl_usdc_payment|xahau_xah_payment]
import puppeteer from 'puppeteer-core';
import { readFileSync } from 'node:fs';
// The article to buy: the first miniature of the catalogue, found on the home page by its URL key —
// never "the first product", which changes with the catalogue.
const PRODUCT = JSON.parse(readFileSync(new URL('../catalog/catalog.json', import.meta.url))).products[0].slug;
const BASE = process.argv[2] ?? 'http://localhost:8088';
const OUT = process.argv[3] ?? '.';
const METHOD = process.argv[4] ?? 'xrp_payment';
const browser = await puppeteer.launch({
  executablePath: process.env.CHROME ?? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  headless: 'new', args: ['--no-sandbox', '--window-size=1280,1000'], defaultViewport: { width: 1280, height: 1000 },
});
const page = await browser.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
const step = async (name, fn) => {
  const t = Date.now();
  try { await fn(); } catch (e) {
    await page.screenshot({ path: `${OUT}/fail-${name}.png`, fullPage: true });
    console.log(`FAIL  ${name}: ${e.message}`); console.log('console errors:', errors.slice(-5)); await browser.close(); process.exit(1);
  }
  await page.screenshot({ path: `${OUT}/${name}.png`, fullPage: true });
  console.log(`ok    ${name} (${((Date.now() - t) / 1000).toFixed(1)} s)`);
};

await step('1-home', async () => {
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle0', timeout: 60000 });
  await page.waitForSelector(`.product-item a[href*="/${PRODUCT}"]`, { timeout: 20000 });
});
await step('2-add-to-cart', async () => {
  const items = await page.$$('.product-item');
  let target = null;
  for (const candidate of items) {
    if (await candidate.$(`a[href*="/${PRODUCT}"]`)) { target = candidate; break; }
  }
  if (!target) throw new Error(`no product item for ${PRODUCT} on the home page`);
  await target.hover();
  await (await target.$('button.tocart')).click();
  await page.waitForFunction(() => document.querySelector('.minicart-wrapper .counter-number')?.textContent.trim() === '1', { timeout: 30000 });
});
await step('3-checkout-shipping', async () => {
  await page.goto(`${BASE}/checkout/`, { waitUntil: 'networkidle0', timeout: 90000 });
  await page.waitForSelector('#customer-email', { visible: true, timeout: 60000 });
  await page.type('#customer-email', 'visitor@example.invalid');
  const fill = async (name, value) => page.type(`#shipping-new-address-form [name="${name}"]`, value);
  await fill('firstname', 'Try'); await fill('lastname', 'Visitor');
  await fill('street[0]', '1 Testnet Way'); await fill('city', 'Berlin'); await fill('postcode', '10115');
  await page.select('#shipping-new-address-form [name="country_id"]', 'DE');
  await fill('telephone', '0301234567');
  await page.waitForSelector('#checkout-shipping-method-load input[type=radio]', { timeout: 30000 });
  const radio = await page.$('#checkout-shipping-method-load input[type=radio]');
  if (radio) await radio.click();
});
await step('4-payment-methods', async () => {
  // Magento recalculates rates while the address is typed; a click during its loader is swallowed.
  await page.waitForFunction(() => !document.querySelector('.loading-mask, .loader')?.offsetParent, { timeout: 30000 });
  await new Promise((r) => setTimeout(r, 1500));
  await page.click('#shipping-method-buttons-container button.continue');
  await new Promise((r) => setTimeout(r, 3000));
  const problems = await page.$$eval('.mage-error, .message-error, div.field-error', (els) => els.filter((e) => e.offsetParent).map((e) => e.textContent.trim()));
  if (problems.length) throw new Error('checkout says: ' + problems.join(' | '));
  await page.waitForSelector(`#${METHOD}`, { visible: true, timeout: 60000 });
  const titles = await page.$$eval('.payment-method-title label span', (els) => els.map((e) => e.textContent.trim()));
  console.log('      payment methods:', titles.join(' | '));
  await page.click(`#${METHOD}`);
});
await step('5-place-order', async () => {
  const button = `.payment-method._active button.checkout`;
  await page.waitForSelector(button, { visible: true, timeout: 20000 });
  // Selecting the method saves it and reloads the totals behind a loader; wait it out.
  await page.waitForFunction(() => ![...document.querySelectorAll('.loading-mask, .loader')].some((e) => e.offsetParent), { timeout: 30000 });
  await new Promise((r) => setTimeout(r, 1500));
  await Promise.all([page.waitForNavigation({ timeout: 60000 }), page.click(button)]);
  await page.waitForFunction(() => location.pathname.includes('/ledger-direct/payment'), { timeout: 60000 });
  await page.waitForSelector('[data-ld-page]', { timeout: 30000 });
});
const view = await page.evaluate(() => ({
  url: location.href,
  state: document.querySelector('[data-ld-page]')?.getAttribute('data-ld-state'),
  amount: document.querySelector('[data-ld-page]')?.getAttribute('data-ld-amount-requested'),
  asset: document.querySelector('[data-ld-page]')?.getAttribute('data-ld-asset'),
}));
console.log('      payment page:', JSON.stringify(view));
if (errors.length) console.log('      console errors:', errors.slice(0, 5));
await browser.close();
if (view.state !== 'waiting' || !view.amount || !view.url.includes('/key/')) {
  console.log('FAIL  the payment page is not the expected one');
  process.exit(1);
}
