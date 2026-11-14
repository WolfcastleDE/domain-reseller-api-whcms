// Renders modules/registrars/domainresellerapi/logo.png (globe mark of domain-reseller-api.de + name,
// 500x160 = 4x the 125x40 WHMCS shows). Run: TEST=make-logo via the playwright service, then copy
// e2e/artifacts/logo.png into the module.
const { chromium } = require('playwright');
(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 500, height: 160 }, deviceScaleFactor: 1 });
  await page.setContent(`<!doctype html><html><head><style>
    html,body{margin:0;background:transparent}
    .wrap{width:500px;height:160px;display:flex;align-items:center;gap:22px;padding:0 18px;box-sizing:border-box;font-family:"DejaVu Sans","Liberation Sans",Arial,sans-serif}
    svg{width:112px;height:112px;flex:none}
    .t{line-height:1.02;color:#111;font-weight:700;font-size:44px;letter-spacing:-0.5px}
    .t small{display:block;font-size:30px;font-weight:600;color:#444;letter-spacing:0}
  </style></head><body><div class="wrap">
    <svg viewBox="0 0 32 32" fill="none"><circle cx="16" cy="16" r="15" fill="#000"/><circle cx="16" cy="16" r="10" stroke="#fff" stroke-width="1.5"/>
    <ellipse cx="16" cy="16" rx="5.5" ry="10" stroke="#fff" stroke-width="1.5"/><line x1="6" y1="16" x2="26" y2="16" stroke="#fff" stroke-width="1.5"/>
    <line x1="8" y1="11" x2="24" y2="11" stroke="#fff" stroke-width=".75" opacity=".6"/><line x1="8" y1="21" x2="24" y2="21" stroke="#fff" stroke-width=".75" opacity=".6"/>
    <circle cx="16" cy="16" r="2.5" fill="#fff"/></svg>
    <div class="t">Domain<small>Reseller API</small></div></div></body></html>`);
  await page.screenshot({ path: '/artifacts/logo.png', omitBackground: true });
  await browser.close();
})();
