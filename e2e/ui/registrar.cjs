// System Settings -> Domain Registrars: activate and configure the module through the admin UI.
const fs = require('fs');
const { ok, browserSession, adminLogin, BASE } = require('./common.cjs');
(async () => {
  const { page, settle, text, finish } = await browserSession('Admin: activate + configure the registrar');
  await adminLogin(page);
  await page.goto(BASE + '/admin/configregistrars.php');
  await settle();
  const row = page.locator('#formholder_domainresellerapi');
  ok('module listed under Domain Registrars', (await row.count()) === 1 && (await row.innerText()).includes('Domain Reseller API'));
  const activate = row.locator('input[value=Activate]:not([disabled])');
  if (await activate.count()) {
    await Promise.all([page.waitForLoadState('networkidle'), activate.click()]);
    await settle();
  }
  const form = page.locator('#domainresellerapiconfig form');
  if (!(await form.isVisible())) await page.locator('#formholder_domainresellerapi input[value=Configure]').click();
  ok('config form shows all settings', /API Key[\s\S]*API URL[\s\S]*Sandbox Mode[\s\S]*Keep API Auto-Renew[\s\S]*Allow Owner Changes[\s\S]*API Timeout/.test(await form.innerText()));

  const key = (process.env.API_KEY_OVERRIDE || fs.readFileSync('/shared/api_key', 'utf8')).trim();

  // Connection test on save (domainresellerapi_config_validate, module >= 0.9.2): a wrong key is rejected
  if (process.env.EXPECT_CONFIG_VALIDATE !== '0') {
    await form.locator('input[name=ApiKey]').fill('drapi_wrongkey0000_' + 'x'.repeat(32));
    await form.locator('input[name=ApiUrl]').fill(process.env.MODULE_API_URL || 'http://localhost:3000');
    await form.locator('input[type=checkbox][name=Sandbox]').check();
    await Promise.all([page.waitForLoadState('networkidle'), form.locator('input[type=submit], button[type=submit]').first().click()]);
    await settle();
    const rejected = (await text()).replace(/\s+/g, ' ');
    const msg = (rejected.match(/Connection test failed[^.]*\.[^.]*\./) || [''])[0];
    console.log('       wrong key: ' + msg);
    ok('wrong API key rejected on save with a clear message', /Connection test failed/.test(rejected) && !/Changes Saved Successfully/i.test(rejected));
    await page.screenshot({ path: '/artifacts/admin-registrar-wrong-key.png', fullPage: false });
    await page.goto(BASE + '/admin/configregistrars.php');
    await settle();
    if (!(await form.isVisible())) await page.locator('#formholder_domainresellerapi input[value=Configure]').click();
  }

  await form.locator('input[name=ApiKey]').fill(key);
  await form.locator('input[name=ApiUrl]').fill(process.env.MODULE_API_URL || 'http://localhost:3000');
  await form.locator('input[type=checkbox][name=Sandbox]').check();
  await form.locator('input[type=checkbox][name=ApiAutoRenew]').uncheck();
  await form.locator('input[type=checkbox][name=ConfirmOwnerChange]').uncheck();
  await Promise.all([page.waitForLoadState('networkidle'), form.locator('input[type=submit], button[type=submit]').first().click()]);
  await settle();
  const body = await text();
  const saved = /Changes Saved Successfully|saved successfully/i.test(body);
  ok('settings saved' + (saved ? '' : ': ' + body.replace(/\s+/g, ' ').slice(0, 400)), saved);
  await page.screenshot({ path: '/artifacts/admin-registrar.png', fullPage: false });

  // Saving again without re-entering the (masked) key keeps the key and passes the connection test
  await page.goto(BASE + '/admin/configregistrars.php');
  await settle();
  if (!(await form.isVisible())) await page.locator('#formholder_domainresellerapi input[value=Configure]').click();
  await form.locator('input[name=Timeout]').fill('30');
  await Promise.all([page.waitForLoadState('networkidle'), form.locator('input[type=submit], button[type=submit]').first().click()]);
  await settle();
  const again = (await text()).replace(/\s+/g, ' ');
  ok('re-save with the masked key passes the connection test', /Changes Saved Successfully/i.test(again), (again.match(/Connection test failed[^.]*\./) || [''])[0]);
  await finish();
})();
