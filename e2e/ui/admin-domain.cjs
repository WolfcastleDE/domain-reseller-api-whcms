// Admin area domain page: registrar commands, the module's custom buttons and saving the form.
const fs = require('fs');
const { ok, browserSession, adminLogin, BASE, logMark, logSince } = require('./common.cjs');
const domains = JSON.parse(fs.readFileSync('/shared/e2e_domains.json', 'utf8'));

(async () => {
  const { page, settle, text, finish } = await browserSession('Admin: domain page, registrar commands, custom buttons');
  await adminLogin(page);
  await page.goto(BASE + '/admin/clientsdomains.php?userid=1');
  await settle();
  const option = page.locator('select[name=id] option, #selectDomain option', { hasText: domains.de });
  const id = await option.first().getAttribute('value').catch(() => null);
  ok('domain selectable on the admin domain page', !!id);
  const open = async () => { await page.goto(`${BASE}/admin/clientsdomains.php?userid=1&id=${id}`); await settle(); };
  await open();
  const buttons = (await page.locator('input[type=button], button, a.btn').allInnerTexts()).map((t) => t.trim()).concat(await page.locator('input[type=button]').evaluateAll((els) => els.map((e) => e.value)));
  for (const label of ['Restore Domain', 'Cancel Deletion', 'Reset Auth Code', 'Enable Managed DNS', 'Disable Managed DNS', 'Get EPP Code', 'Modify Contact Details', 'Renew', 'Request Delete']) {
    ok(`button "${label}" present`, buttons.includes(label));
  }
  await page.screenshot({ path: '/artifacts/admin-domain.png', fullPage: false });

  // Saving the form unchanged must not try to change the registrar lock of a TLD without lock
  let mark = logMark();
  await Promise.all([page.waitForLoadState('networkidle'), page.click('#btnSaveChanges, input[value="Save Changes"], button:has-text("Save Changes")')]);
  await settle();
  const calls = [...new Set(logSince(mark, null, 'params').map((e) => e.caller))];
  console.log('       module functions called by "Save Changes": ' + JSON.stringify(calls));
  let body = (await text()).replace(/\s+/g, ' ');
  ok('Save Changes (unchanged) succeeds without registrar error', !/Registrar Error|error/i.test((await page.locator('.alert-danger, .errorbox').allInnerTexts()).join(' ')), (await page.locator('.alert-danger, .errorbox').allInnerTexts()).join(' / '));
  ok('Save Changes does not call SaveRegistrarLock', !calls.includes('SaveRegistrarLock'), JSON.stringify(calls));

  const runButton = async (label) => {
    await open();
    mark = logMark();
    await page.locator(`input[type=button][value="${label}"], button:text-is("${label}")`).first().click();
    await page.waitForTimeout(800);
    const confirm = page.locator('.modal:visible button:has-text("Yes"), .modal:visible .btn-primary, .modal:visible button:has-text("OK")');
    if (await confirm.count()) await confirm.first().click();
    await page.waitForLoadState('networkidle');
    await settle();
    const msg = (await page.locator('.alert, .successbox, .errorbox, .infobox').allInnerTexts()).join(' / ').replace(/\s+/g, ' ');
    return { msg, calls: logSince(mark, null, 'request').map((e) => `${e.caller}: ${e.method} ${e.endpoint} ${e.status}`) };
  };
  for (const [label, fn] of [['Reset Auth Code', 'ResetAuthCode'], ['Disable Managed DNS', 'DisableManagedDns'], ['Enable Managed DNS', 'EnableManagedDns']]) {
    const r = await runButton(label);
    ok(`custom button "${label}" -> ${fn} succeeds`, r.calls.some((c) => c.startsWith(fn + ':') && / 200$/.test(c)) && !/error/i.test(r.msg), JSON.stringify(r));
  }
  const epp = await runButton('Get EPP Code');
  ok('Get EPP Code shows the code', /EPP Code|SANDBOX-AUTH/i.test(epp.msg), JSON.stringify(epp));
  await finish();
})();
