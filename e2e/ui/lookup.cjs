// System Settings -> Domain Pricing -> Lookup Provider: use the module for availability checks.
const { ok, browserSession, adminLogin, BASE } = require('./common.cjs');
(async () => {
  const { page, settle, finish } = await browserSession('Admin: domain lookup provider');
  await adminLogin(page);
  await page.goto(BASE + '/admin/configdomains.php');
  await settle();
  await page.click('#changeLookupProvider');
  await page.waitForSelector('.lookup-provider[data-provider=Registrar]');
  await page.click('.lookup-provider[data-provider=Registrar]');
  await page.waitForTimeout(800);
  const entry = page.locator('.lookup-providers-registrars a[data-provider=domainresellerapi]');
  ok('module offered as lookup registrar (has CheckAvailability)', (await entry.count()) === 1);
  await entry.click();
  await page.waitForTimeout(3000);
  await settle();
  const body = (await page.locator('.modal:visible').first().innerText().catch(() => '')).replace(/\s+/g, ' ');
  console.log('       modal: ' + body.slice(0, 300));
  // Configure: TLDs that are checked/suggested in addition to the searched one
  await page.goto(BASE + '/admin/configdomains.php');
  await settle();
  await page.click('#configureLookupProvider');
  await page.waitForTimeout(2500);
  const modal = page.locator('.modal:visible').first();
  await modal.locator('select[name*="suggestTlds"]').selectOption(['.de', '.net', '.org', '.eu', '.ai']);
  await page.click('#btnSaveLookupConfiguration').catch(() => {});
  await page.waitForTimeout(2500);
  await page.goto(BASE + '/admin/configdomains.php');
  await settle();
  // Spotlight TLDs: checked by CheckAvailability with every search (several TLDs per call)
  for (const tld of ['.com', '.de', '.net', '.ai']) {
    const add = page.locator(`div.spotlight:has(input.tld[value="${tld}"]) button.add-spotlight`).first();
    if (await add.count() && await add.isEnabled()) { await add.click(); await page.waitForTimeout(1200); }
  }
  const spot = (await page.locator('#spotlightTlds').innerText().catch(() => '')).replace(/\s+/g, ' ');
  console.log('       spotlight TLDs: ' + spot);
  ok('spotlight TLDs configured', ['.com', '.de', '.net', '.ai'].every((t) => spot.includes(t)), spot);
  const panel = (await page.locator('.selected-provider').first().innerText().catch(() => '')) + ' ' + (await page.locator('text=Lookup Provider').locator('..').locator('..').innerText().catch(() => ''));
  console.log('       lookup panel: ' + panel.replace(/\s+/g, ' ').slice(0, 200));
  await finish();
})();
