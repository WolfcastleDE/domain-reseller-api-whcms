// Utilities -> Registrar TLD Sync: list TLDs + cost prices from the module and import them.
const { ok, browserSession, adminLogin, BASE } = require('./common.cjs');
(async () => {
  const { page, settle, finish } = await browserSession('Admin: Registrar TLD Sync');
  await adminLogin(page);
  await page.goto(BASE + '/admin/index.php?rp=/admin/utilities/tools/tldsync/import');
  await settle();
  await page.click('a[data-registrar=domainresellerapi]');
  await page.waitForSelector('tr[data-tld=".com"]', { timeout: 60000 });
  await settle();

  const row = async (tld) => {
    const r = page.locator(`tr[data-tld="${tld}"]`);
    if (!(await r.count())) return null;
    const cells = await r.locator('td').allInnerTexts();
    const remote = await r.locator('.remote-pricing').allInnerTexts();
    return { cells: cells.map((c) => c.replace(/\s+/g, ' ').trim()), remote: remote.map((c) => c.trim()) };
  };
  const ai = await row('.ai');
  const de = await row('.de');
  const com = await row('.com');
  console.log('       .ai', JSON.stringify(ai));
  console.log('       .de', JSON.stringify(de));
  console.log('       .com', JSON.stringify(com));
  ok('TLDs listed (.com, .de, .ai, .it)', !!com && !!de && !!ai && !!(await row('.it')));
  ok('.ai: minimum term 2 years', !!ai && ai.cells.some((c) => /^2 Years$/.test(c)));
  ok('.ai: register/renew cost is the 2-year total 195.12', !!ai && ai.remote[0] === '195.12' && ai.remote[1] === '195.12', ai && ai.remote);
  ok('.ai: transfer cost is the transfer total 195.14', !!ai && ai.remote[2] === '195.14', ai && ai.remote);

  // Import everything with the default 20 % margin, redemption fees and auto registration on
  await page.evaluate(() => {
    const $ = window.jQuery;
    $('#inputSyncRedemption').bootstrapSwitch('state', true);
    $('#inputSetAutoRegistrar').bootstrapSwitch('state', true);
  });
  await page.click('a[role=tab]:has-text("All"), .nav-tabs a:text-is("All")');
  await page.waitForTimeout(800);
  await page.locator('.tab-pane.active thead input[type=checkbox]').first().click();
  await page.waitForTimeout(1500);
  const count = (await page.innerText('#tldImportCount')).trim();
  console.log('       import count: ' + count);
  await page.screenshot({ path: '/artifacts/admin-tldsync.png', fullPage: false });
  await page.click('#doTldImport');
  await page.waitForTimeout(3000);
  const confirm = page.locator('.modal:visible button:has-text("Import"), .modal:visible button:has-text("Yes"), .modal:visible .btn-primary');
  if (await confirm.count()) { await confirm.first().click(); }
  await page.waitForTimeout(8000);
  await settle();
  const body = (await page.innerText('body')).replace(/\s+/g, ' ');
  ok('import finished', /success|imported|complete/i.test(body), body.slice(0, 500));
  await page.screenshot({ path: '/artifacts/admin-tldsync-done.png', fullPage: false });
  await finish();
})();
