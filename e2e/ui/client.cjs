// Client area (Twenty-One): domain overview, nameservers, contacts, private nameservers, DNS, EPP code.
// Checks the outcome at the Domain Reseller API (sandbox) directly.
const fs = require('fs');
const { ok, browserSession, clientLogin, BASE } = require('./common.cjs');

const API = process.env.API_URL || 'http://127.0.0.1:13001';
const KEY = fs.readFileSync('/shared/api_key', 'utf8').trim();
const domains = JSON.parse(fs.readFileSync('/shared/e2e_domains.json', 'utf8'));
const api = async (path) => (await fetch(API + '/api/v1' + path, { headers: { Authorization: 'Bearer ' + KEY, 'x-sandbox': 'true' } })).json();

(async () => {
  const { page, settle, text, finish } = await browserSession('Client area (Twenty-One)');
  await clientLogin(page);
  ok('client logged in', page.url().includes('clientarea.php'));

  await page.goto(BASE + '/clientarea.php?action=domains');
  await settle();
  const listing = await text();
  ok('My Domains lists the registered domains', Object.values(domains).every((d) => listing.includes(d)));
  const idOf = async (domain) => {
    const href = await page.locator(`a[href*="domaindetails"]`, { hasText: domain }).first().getAttribute('href').catch(() => null);
    if (href) return new URL(href, BASE).searchParams.get('id');
    const row = page.locator('tr', { hasText: domain }).first();
    const onclick = (await row.getAttribute('onclick').catch(() => '')) || '';
    return (onclick.match(/id=(\d+)/) || [])[1] || (await row.getAttribute('data-id').catch(() => null));
  };
  const deId = await idOf(domains.de);
  const comId = await idOf(domains.com);
  ok('domain ids found', !!deId && !!comId, JSON.stringify({ deId, comId }));

  // Overview + nameservers (GetNameservers / SaveNameservers)
  await page.goto(`${BASE}/clientarea.php?action=domaindetails&id=${comId}`);
  await settle();
  ok('domain overview shows status Active', /Status:\s*Active/.test(await text()));
  await page.click('a[href="#tabNameservers"]');
  await page.waitForSelector('#inputNs1', { state: 'visible' });
  ok('nameserver tab shows current nameservers', (await page.inputValue('#inputNs1')).startsWith('ns1.example-dns.'));
  await page.check('input[name=nschoice][value=custom]').catch(() => {});
  await page.fill('#inputNs1', 'ns1.client-dns.example');
  await page.fill('#inputNs2', 'ns2.client-dns.example');
  await page.fill('#inputNs3', '');
  await Promise.all([page.waitForLoadState('networkidle'), page.locator('form:has(input[name=sub][value=savens]) button[type=submit]').click()]);
  await settle();
  let body = await text();
  ok('save nameservers: success message', /changes have been saved|successfully/i.test(body), body.replace(/\s+/g, ' ').match(/(Error|error|failed)[^.]{0,200}/)?.[0]);
  let d = await api('/domains/' + domains.com);
  ok('API has the new nameservers', JSON.stringify(d.data?.nameservers) === JSON.stringify(['ns1.client-dns.example', 'ns2.client-dns.example']), JSON.stringify(d.data?.nameservers));

  // Contacts (GetContactDetails / SaveContactDetails) with non-ASCII data
  await page.goto(`${BASE}/clientarea.php?action=domaincontacts&domainid=${deId}`);
  await settle();
  const field = (role, name) => page.locator(`input[name="contactdetails[${role}][${name}]"]`);
  if (await page.locator('#Registrant2').count()) await page.check('#Registrant2');
  ok('contact form prefilled from the API', (await field('Registrant', 'Last Name').inputValue()) !== '' && (await field('Registrant', 'Phone Number').inputValue()) !== '');
  await field('Registrant', 'First Name').fill('Jürgen');
  await field('Registrant', 'Last Name').fill('Weiß');
  await field('Registrant', 'Address 1').fill('Königsallee 92');
  await field('Registrant', 'City').fill('Düsseldorf');
  await field('Registrant', 'Postcode').fill('40212');
  await page.screenshot({ path: '/artifacts/client-contacts.png', fullPage: false });
  await Promise.all([page.waitForLoadState('networkidle'), page.locator('#frmDomainContactModification button[type=submit], #frmDomainContactModification input[type=submit]').first().click()]);
  await settle();
  body = (await text()).replace(/\s+/g, ' ');
  ok('save contacts: success message', /changes have been saved|successfully/i.test(body), body.match(/(Error|error)[^.]{0,300}/)?.[0]);
  d = await api('/domains/' + domains.de);
  const owner = await api('/contacts/' + d.data?.contacts?.owner);
  console.log('       owner at API: ' + JSON.stringify({ firstName: owner.data?.firstName, lastName: owner.data?.lastName, street: owner.data?.street, houseNumber: owner.data?.houseNumber, city: owner.data?.city }));
  ok('API owner contact updated with the exact (UTF-8) input', owner.data?.firstName === 'Jürgen' && owner.data?.lastName === 'Weiß' && owner.data?.street === 'Königsallee' && owner.data?.houseNumber === '92' && owner.data?.city === 'Düsseldorf');

  // Private nameservers (RegisterNameserver / ModifyNameserver / DeleteNameserver)
  const nsForm = (sub) => page.locator(`form:has(input[name=sub][value=${sub}])`);
  const submitNs = async (sub, fill) => {
    await page.goto(`${BASE}/clientarea.php?action=domainregisterns&domainid=${deId}`);
    await settle();
    await fill(nsForm(sub));
    await Promise.all([page.waitForLoadState('networkidle'), nsForm(sub).locator('button[type=submit]').click()]);
    await settle();
    return (await text()).replace(/\s+/g, ' ');
  };
  body = await submitNs('register', async (f) => { await f.locator('input[name=ns]').fill('ns1'); await f.locator('input[name=ipaddress]').fill('192.0.2.53'); });
  ok('register child nameserver ns1.' + domains.de, /success|saved/i.test(body), body.match(/(Error|error)[^.]{0,200}/)?.[0]);
  body = await submitNs('modify', async (f) => { await f.locator('input[name=ns]').fill('ns1'); await f.locator('input[name=currentipaddress]').fill('192.0.2.53'); await f.locator('input[name=newipaddress]').fill('192.0.2.54'); });
  ok('modify child nameserver IP', /success|saved/i.test(body), body.match(/(Error|error)[^.]{0,200}/)?.[0]);
  body = await submitNs('delete', async (f) => { await f.locator('input[name=ns]').fill('ns1'); });
  ok('delete child nameserver', /success|saved/i.test(body), body.match(/(Error|error)[^.]{0,200}/)?.[0]);

  // DNS management (GetDNS / SaveDNS), URL -> Redirect
  await page.goto(`${BASE}/clientarea.php?action=domaindns&domainid=${deId}`);
  await settle();
  const hosts = page.locator('input[name="dnsrecordhost[]"]');
  const n = await hosts.count();
  ok('DNS page shows existing records', n > 1);
  // the last row is the empty "new record" row
  const shop = 'shop' + Date.now().toString(36).slice(-4);
  await hosts.nth(n - 1).fill(shop);
  await page.locator('select[name="dnsrecordtype[]"]').nth(n - 1).selectOption('AAAA');
  await page.locator('input[name="dnsrecordaddress[]"]').nth(n - 1).fill('2001:db8::1');
  await page.screenshot({ path: '/artifacts/client-dns.png', fullPage: false });
  await Promise.all([page.waitForLoadState('networkidle'), page.locator('form:has(input[name=sub][value=save]) button[type=submit]').first().click()]);
  await settle();
  body = (await text()).replace(/\s+/g, ' ');
  const dnsErrors = (await page.locator('.alert-danger:visible').allInnerTexts()).map((t) => t.trim()).filter(Boolean).join(' / ');
  ok('save DNS: no error (the template shows no success message)', dnsErrors === '', dnsErrors);
  const zone = await api('/dns/' + domains.de);
  const recs = (zone.data?.records || []).map((r) => `${r.type} ${r.name} ${r.content}`);
  console.log('       zone: ' + JSON.stringify(recs));
  ok('API zone has the new AAAA record and the Redirect', recs.filter((r) => r.startsWith(`AAAA ${shop} 2001:db8::1`)).length === 1 && recs.some((r) => r.startsWith('Redirect go ')));

  // EPP code
  await page.goto(`${BASE}/clientarea.php?action=domaingetepp&domainid=${deId}`);
  await settle();
  ok('EPP code shown', /EPP Code for your domain is:\s*\S+/.test(await text()));

  await finish();
})();
