// Shared helpers for the WHMCS browser tests (Playwright).
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:18081';
const ADMIN = { user: 'admin', password: 'e2e-password-123' };
const CLIENT = { email: 'kunde@e2e.test', password: 'e2e-password-123' };
let failures = 0;

function ok(label, condition, detail = '') {
  console.log((condition ? '  \x1b[32mPASS\x1b[0m ' : '  \x1b[31mFAIL\x1b[0m ') + label + (condition || !detail ? '' : ' :: ' + detail));
  if (!condition) failures++;
  return condition;
}

async function browserSession(title, { width = 1400, height = 1000 } = {}) {
  console.log('\n\x1b[1m' + title + '\x1b[0m');
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width, height } });
  const errors = [];
  page.on('dialog', (d) => d.accept().catch(() => {}));
  page.on('pageerror', (e) => errors.push(page.url() + ' :: ' + e.message));
  page.on('response', (r) => { if (r.status() >= 500) errors.push(r.status() + ' ' + r.url()); });
  const settle = () => page.waitForLoadState('networkidle').then(() => page.waitForTimeout(500));
  const text = () => page.innerText('body');
  const finish = async () => {
    ok('no JavaScript errors or HTTP 5xx' + (errors.length ? ': ' + JSON.stringify(errors) : ''), errors.length === 0);
    await browser.close();
    process.exit(failures);
  };
  return { browser, page, settle, text, finish, errors };
}

async function adminLogin(page) {
  await page.goto(BASE + '/admin/login.php');
  await page.fill('input[name=username]', ADMIN.user);
  await page.fill('input[name=password]', ADMIN.password);
  await Promise.all([page.waitForLoadState('networkidle'), page.click('#btnLogin, input[type=submit], button[type=submit]')]);
  return page.url();
}

async function clientLogin(page) {
  await page.goto(BASE + '/index.php?rp=/login');
  await page.fill('#inputEmail', CLIENT.email);
  await page.fill('#inputPassword', CLIENT.password);
  await Promise.all([page.waitForURL((u) => !u.toString().includes('/login'), { timeout: 20000 }).catch(() => {}), page.click('#login')]);
  await page.waitForLoadState('networkidle');
  return page.url();
}

// Capture log written by the test hook in WHMCS (module params + API requests)
const fs = require('fs');
const LOG = '/shared/drapi-e2e.jsonl';
const logMark = () => { try { return fs.statSync(LOG).size; } catch { return 0; } };
const logSince = (mark, caller = null, type = null) => {
  let data = '';
  try { const fd = fs.openSync(LOG, 'r'); const size = fs.fstatSync(fd).size; const buf = Buffer.alloc(Math.max(0, size - mark)); fs.readSync(fd, buf, 0, buf.length, mark); fs.closeSync(fd); data = buf.toString('utf8'); } catch {}
  return data.split('\n').filter(Boolean).map((l) => JSON.parse(l)).filter((e) => (!caller || e.caller === caller) && (!type || e.type === type));
};

module.exports = { ok, browserSession, adminLogin, clientLogin, BASE, ADMIN, CLIENT, logMark, logSince };
