// Marketplace screenshots from the running installation (client area theme: Twenty-One).
// The fixed "Dev License" notice at the bottom of every page is cropped off, nothing on the page
// is altered; the license key never appears on these pages. Output: /artifacts/marketplace-*.png
const fs = require('fs');
const { ok, browserSession, adminLogin, clientLogin, BASE } = require('./common.cjs');
const demo = JSON.parse(fs.readFileSync('/shared/demo_domain.json', 'utf8'));
const W = 1440, H = 1000;

// Rectangle of the dev license notice (fixed at the bottom in the admin area, a box above the
// content in the client area)
async function licenseNotice(page) {
  return page.evaluate(() => {
    const hits = [...document.querySelectorAll('body *')].filter((e) => e.children.length < 6 && /^\s*Dev License:/.test(e.innerText || '') && e.getBoundingClientRect().height > 0);
    if (!hits.length) return null;
    let el = hits.sort((a, b) => a.getBoundingClientRect().height - b.getBoundingClientRect().height)[0];
    for (let p = el; p && p !== document.body; p = p.parentElement) {
      if (getComputedStyle(p).position === 'fixed') { el = p; break; }
      if (p.getBoundingClientRect().width < 1500 && /alert|license|banner/i.test(p.className || '')) el = p;
    }
    const r = el.getBoundingClientRect();
    return { top: r.top, bottom: r.bottom, fixed: getComputedStyle(el).position === 'fixed' };
  });
}

// Screenshot of the union of the given elements (page coordinates of the viewport), never
// including the dev license notice: the region starts below an in-flow notice and ends above a
// fixed one.
async function shot(page, file, selectors, pad = 16) {
  const rect = await page.evaluate((sels) => {
    const rs = sels.flatMap((s) => [...document.querySelectorAll(s)]).filter((e) => e.getBoundingClientRect().height > 0).map((e) => e.getBoundingClientRect());
    if (!rs.length) return null;
    return { x: Math.min(...rs.map((r) => r.left)), y: Math.min(...rs.map((r) => r.top)), r: Math.max(...rs.map((r) => r.right)), b: Math.max(...rs.map((r) => r.bottom)) };
  }, selectors);
  const notice = await licenseNotice(page);
  let x = Math.max(0, rect.x - pad), y = Math.max(0, rect.y - pad), right = Math.min(W, rect.r + pad), bottom = Math.min(H, rect.b + pad);
  // Admin area: a bar pinned to the bottom of the viewport (sticky/fixed) -> end above it
  const bottomBar = notice && (notice.fixed || notice.top > H * 0.6);
  if (bottomBar) bottom = Math.min(bottom, notice.top - 14);
  // Client area: a box above the content -> start right at the content
  else if (notice && notice.top < rect.y + 1) y = Math.max(y, rect.y - 1);
  if (process.env.DEBUG_SHOTS) console.log('       ' + file + ' ' + JSON.stringify({ rect, notice, x, y, right, bottom }));
  await page.screenshot({ path: file, clip: { x, y, width: right - x, height: bottom - y } });
  return `${Math.round(right - x)}x${Math.round(bottom - y)}`;
}

(async () => {
  const { page, settle, browser, finish } = await browserSession('Marketplace screenshots', { width: W, height: H });

  // 1. Client area: domain search with live availability, spotlight TLDs and suggestions
  await page.goto(BASE + '/cart.php?a=add&domain=register');
  await settle();
  await page.fill('#inputDomain', 'musterfirma-shop.de');
  await page.click('#btnCheckAvailability');
  await page.waitForSelector('#spotlightTlds .domain-lookup-result:visible, #primaryLookupResult:visible', { timeout: 30000 }).catch(() => {});
  await page.waitForTimeout(6000);
  await settle();
  await page.evaluate(() => { const el = document.querySelector('.domain-checker-container'); if (el) window.scrollTo(0, Math.max(0, el.getBoundingClientRect().top + window.scrollY - 20)); });
  await page.waitForTimeout(500);
  let h = await shot(page, '/artifacts/marketplace-1-domain-search.png', ['.domain-checker-container', '#DomainSearchResults', '#primaryLookupResult', '#spotlightTlds', '.suggested-domains']);
  ok(`1 domain search (${h})`, /musterfirma-shop/.test(await page.innerText('body')));

  // 2. Admin: Registrar TLD Sync with cost prices (incl. .ai for its 2-year minimum term)
  await adminLogin(page);
  await page.goto(BASE + '/admin/index.php?rp=/admin/utilities/tools/tldsync/import');
  await settle();
  await page.locator('.alert-warning .close, #sslWarning .close, [data-dismiss=alert]').first().click().catch(() => {});
  await page.click('a[data-registrar=domainresellerapi]');
  await page.waitForSelector('tr[data-tld=".ai"]', { timeout: 60000, state: 'attached' });
  await settle();
  // The category tab that lists .ai (2-year minimum term)
  const aiTab = await page.evaluate(() => {
    const panes = [...document.querySelectorAll('.tab-pane')].filter((p) => p.querySelector('tr[data-tld=".ai"]'));
    const pane = panes.find((p) => p.querySelectorAll('tr[data-tld]').length < 20) || panes[0];
    return pane ? pane.id : null;
  });
  console.log('       .ai tab: ' + aiTab);
  await page.click(aiTab ? `.nav-tabs a[href="#${aiTab}"]` : '.nav-tabs a:text-is("Technology")').catch(() => {});
  await page.waitForTimeout(800);
  await page.evaluate(() => { const el = document.querySelector('#btnSelectRegistrar'); if (el) window.scrollTo(0, el.getBoundingClientRect().top + window.scrollY - 70); });
  await page.waitForTimeout(500);
  h = await shot(page, '/artifacts/marketplace-2-tld-sync.png', ['#btnSelectRegistrar', '#doTldImport', '.tab-content .tab-pane.active table'], 14);
  ok(`2 TLD sync (${h})`, (await page.locator('tr[data-tld=".ai"]').count()) > 0);

  // 3. Client area: DNS management of a domain (records incl. URL redirect)
  const ctx = await browser.newContext({ viewport: { width: W, height: H } });
  const client = await ctx.newPage();
  await clientLogin(client);
  await client.goto(`${BASE}/clientarea.php?action=domaindns&domainid=${demo.id}`);
  await client.waitForLoadState('networkidle');
  await client.waitForTimeout(800);
  await client.evaluate(() => { const el = document.querySelector('form[action*=domaindns]'); if (el) window.scrollTo(0, Math.max(0, el.closest('.card').getBoundingClientRect().top + window.scrollY - 20)); });
  h = await shot(client, '/artifacts/marketplace-3-dns-management.png', ['.primary-content .card']);
  ok(`3 DNS management (${h})`, (await client.locator('input[name="dnsrecordhost[]"]').count()) > 3);

  await finish();
})();
