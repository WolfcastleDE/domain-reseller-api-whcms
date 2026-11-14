// Cart domain search (lookup provider = this module): CheckAvailability, GetDomainSuggestions, IDN, bulk.
const fs = require('fs');
const { ok, browserSession, BASE, logMark, logSince } = require('./common.cjs');
const domains = JSON.parse(fs.readFileSync('/shared/e2e_domains.json', 'utf8'));

(async () => {
  const { page, settle, text, finish } = await browserSession('Client: domain search, suggestions, bulk');
  const search = async (term) => {
    const mark = logMark();
    await page.goto(BASE + '/cart.php?a=add&domain=register');
    await settle();
    await page.fill('#inputDomain', term);
    await page.click('#btnCheckAvailability');
    await page.waitForSelector('#primaryLookupResult:not(.hidden), .domain-checker-result-headline', { timeout: 30000 }).catch(() => {});
    await page.waitForTimeout(3000);
    await settle();
    return { body: (await text()).replace(/\s+/g, ' '), log: logSince(mark) };
  };
  const params = (log, fn) => (log.find((e) => e.type === 'params' && e.caller === fn) || {}).params;

  let r = await search('e2e-free-name.com');
  ok('available domain shown as available', /e2e-free-name\.com is available/i.test(r.body), r.body.slice(0, 300));
  const check = params(r.log, 'CheckAvailability');
  console.log('       CheckAvailability params: ' + JSON.stringify({ searchTerm: check?.searchTerm, tldsToInclude: check?.tldsToInclude, isIdnDomain: check?.isIdnDomain, premiumEnabled: check?.premiumEnabled }));
  ok('param tldsToInclude received (array)', Array.isArray(check?.tldsToInclude) && check.tldsToInclude.length > 0);
  const bulk = r.log.find((e) => e.type === 'request' && e.endpoint === '/api/v1/domains/check');
  ok('availability checked via POST /domains/check (bulk endpoint)', !!bulk && bulk.status === 200);
  const sugg = params(r.log, 'GetDomainSuggestions');
  console.log('       GetDomainSuggestions params: ' + JSON.stringify({ tldsToInclude: sugg?.tldsToInclude, suggestionSettings: sugg?.suggestionSettings }));
  ok('suggestions listed from GetDomainSuggestions', !!sugg && /Suggested Domains[\s\S]*e2e-free-name\.(de|net|org|eu|ai)/.test(r.body));
  ok('.ai suggestion priced for its 2-year minimum (234,14 €)', r.body.includes('234,14'));

  r = await search(domains.com);
  ok(`registered domain ${domains.com} shown as unavailable`, new RegExp(domains.com.replace('.', '\\.') + ' is unavailable|is already registered|unavailable', 'i').test(r.body), r.body.slice(0, 400));

  r = await search('müller-e2e.de');
  const idn = params(r.log, 'CheckAvailability');
  console.log('       IDN params: ' + JSON.stringify({ searchTerm: idn?.searchTerm, punyCodeSearchTerm: idn?.punyCodeSearchTerm, isIdnDomain: idn?.isIdnDomain }));
  const idnReq = r.log.find((e) => e.type === 'request' && e.caller === 'CheckAvailability');
  ok('IDN search checks the punycode name', JSON.stringify(idnReq?.body?.domains || []).includes('xn--mller-e2e-'), JSON.stringify(idnReq?.body));

  // Spotlight TLDs (configured in lookup.cjs): one CheckAvailability call with several TLDs -> one bulk request
  r = await search('e2e-spotlight-name');
  const spot = r.log.filter((e) => e.type === 'params' && e.caller === 'CheckAvailability').map((e) => e.params.tldsToInclude).find((t) => t.length > 1);
  const spotReq = r.log.filter((e) => e.type === 'request' && e.caller === 'CheckAvailability').map((e) => e.body.domains).find((d) => d.length > 1);
  console.log('       spotlight tldsToInclude: ' + JSON.stringify(spot) + ' -> POST /domains/check ' + JSON.stringify(spotReq));
  ok('several TLDs checked in one POST /domains/check', !!spot && !!spotReq && spotReq.length === spot.length);
  const spotText = (await page.locator('#spotlightTlds').innerText().catch(() => '')).replace(/\s+/g, ' ');
  console.log('       spotlight results: ' + spotText);
  ok('spotlight results rendered (.com/.de/.net/.ai)', /\.com/.test(spotText) && /\.ai/.test(spotText));
  await page.screenshot({ path: '/artifacts/client-search.png', fullPage: false });
  await finish();
})();
