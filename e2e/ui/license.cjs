// Logs into the admin area, accepts the EULA on first login and reports the license state
// (the key itself is never printed or captured in screenshots).
const { ok, browserSession, adminLogin, BASE } = require('./common.cjs');
const mask = (s) => s.replace(/\s+/g, ' ').replace(/Dev-[0-9a-f]+/gi, '<license>');
(async () => {
  const { page, text, settle, finish } = await browserSession('WHMCS license check');
  let url = await adminLogin(page);
  const agree = page.locator('button:has-text("I Agree"), input[value*="Agree" i], a:has-text("I Agree")');
  if (await agree.count()) {
    await Promise.all([page.waitForLoadState('networkidle'), agree.first().click()]);
    await settle();
    console.log('  EULA accepted');
  }
  await page.goto(BASE + '/admin/index.php');
  await settle();
  url = page.url();
  let body = await text();
  console.log('  admin home: ' + url.replace(BASE, ''));
  const licenseError = /licenseerror/i.test(url) || /License (Invalid|Suspended|Expired|Error)|Licensing Error|Invalid (Domain|IP|Directory)/i.test(body);
  if (licenseError) console.log('  page text: ' + mask(body).slice(0, 2000));
  ok('admin area accepted by the WHMCS license check', !licenseError && !url.includes('login.php'));

  await page.goto(BASE + '/admin/systemlicense.php');
  await settle();
  body = await text();
  const info = mask(body).match(/Registered To.{0,400}/i);
  console.log('  license info: ' + (info ? info[0].replace(/Licen[cs]e Key:? ?\S+/i, 'License Key: <hidden>') : mask(body).slice(0, 600)));
  await finish();
})();
