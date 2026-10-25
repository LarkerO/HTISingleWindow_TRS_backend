const signedHeaders = require('./signed-http.cjs');
('use strict');
const {
  waitResponse,
  workbenchUrl,
  fillField,
  choose,
  selectOptions,
  confirmDialog,
  reasonDialog,
} = require('./workbench-ui.cjs');
const fs = require('node:fs'),
  path = require('node:path'),
  { execFileSync } = require('node:child_process');
let playwright;
try {
  playwright = require('playwright');
} catch {
  playwright = require('C:/Users/lxhor/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
}
const root = path.resolve(__dirname, '..'),
  suffix = require('node:crypto').randomBytes(5).toString('hex').toUpperCase();
function fixture(mode) {
  const script = root + '/tests/browser-feature-fixture.php';
  let output;
  if (
    process.platform === 'win32' &&
    (process.env.WSL_BACKEND_PATH || root.startsWith('\\\\wsl.localhost\\Debian\\'))
  ) {
    const linuxScript = process.env.WSL_BACKEND_PATH
      ? process.env.WSL_BACKEND_PATH + '/tests/browser-feature-fixture.php'
      : script.slice('\\\\wsl.localhost\\Debian'.length).replaceAll('\\', '/');
    output = execFileSync('wsl', ['-d', 'Debian', '--', 'php', linuxScript, mode, suffix], { encoding: 'utf8' });
  } else output = execFileSync('php', [script, mode, suffix], { encoding: 'utf8' });
  return JSON.parse(output);
}
(async () => {
  let browser;
  try {
    const f = fixture('setup');
    const key = fs
      .readFileSync(root + '/.env', 'utf8')
      .match(/^TICKETING_API_KEY\s*=\s*(.+)$/m)[1]
      .trim();
    browser = await playwright.chromium.launch({ headless: true, channel: process.env.BROWSER_CHANNEL || 'msedge' });
    const page = await browser.newPage({ viewport: { width: 1440, height: 1100 } }),
      errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto(workbenchUrl);
    await fillField(page, 'apiKey', key);
    await page.locator('#connect').click();
    await page.locator('#status').filter({ hasText: '连接成功' }).waitFor();
    await fillField(page, 'tripTrainNumber', f.number);
    const reply = waitResponse(
      page,
      (r) => r.url().includes('/api/v1/trips?') && r.url().includes('train_number=' + f.number),
    );
    await page.locator('#browse').click();
    const result = await (await reply).json();
    if (result.total < 1 || result.data.some((t) => t.train_number !== f.number))
      throw Error('Browser number filter failed');
    await page.locator('#tripResults strong').first().filter({ hasText: f.number }).waitFor();
    await choose(page, 'origin', f.origin);
    await choose(page, 'destination', f.destination);
    await fillField(page, 'journeyDate', f.date);
    await fillField(page, 'journeyTrainNumber', f.number);
    const from = f.departure.slice(11, 16),
      end = new Date(f.departure.slice(0, 19).replace(' ', 'T') + 'Z');
    end.setUTCMinutes(end.getUTCMinutes() + 1);
    const to = end.toISOString().slice(11, 16);
    await fillField(page, 'journeyTimeFrom', from);
    if (to > from) await fillField(page, 'journeyTimeTo', to);
    const journeysReply = waitResponse(
      page,
      (r) => r.url().includes('/api/v1/journeys?') && r.url().includes('train_number=' + f.number),
    );
    await page.locator('#search').click();
    const journeys = (await (await journeysReply).json()).data;
    if (
      !journeys.length ||
      journeys.some(
        (t) =>
          t.train_number !== f.number ||
          t.departure_at.slice(11, 16) < from ||
          (to > from && t.departure_at.slice(11, 16) >= to),
      )
    )
      throw Error('Browser timed interval filter failed');
    await page.locator('#status').filter({ hasText: '查到' }).waitFor();
    await page.locator('#journeyResults tbody tr button').first().click();
    if (
      !(await page.locator('#selectedJourney').textContent()).includes(f.number) ||
      !(await page.locator('#selectedJourney').textContent()).includes('数据库 ID')
    )
      throw Error('Selected journey loses number or ID');
    console.log('PASS Browser date/number/interval/time filtering and distinct selected trip ID');
    await page.locator('#manageTab').click();
    await choose(page, 'whitelistOperator', f.operator);
    await page.waitForFunction(() => document.querySelector('#whitelistMode input:checked')?.value === '0');
    await choose(page, 'whitelistAgency', f.agency);
    await page.locator('#addWhitelistAgency').click();
    await page.locator('#status').filter({ hasText: '出票方已加入白名单' }).waitFor();
    await page.locator('#whitelistList tbody tr').filter({ hasText: f.agency }).waitFor();
    await choose(page, 'whitelistMode', '1');
    await page.locator('#saveWhitelistMode').click();
    await page.locator('#status').filter({ hasText: '运营方白名单模式已保存' }).waitFor();
    const w = await page.request.get('http://localhost:8000/api/v1/admin/operators/' + f.operator + '/agencies', {
      headers: signedHeaders(key, 'GET', '/api/v1/admin/operators/' + f.operator + '/agencies'),
    });
    if (!(await w.json()).data.enabled) throw Error('Whitelist toggle not saved');
    await page
      .locator('#whitelistList tbody tr')
      .filter({ hasText: f.agency })
      .getByRole('button', { name: '移出白名单' })
      .click();
    await page.locator('#status').filter({ hasText: '出票方已移出白名单' }).waitFor();
    if ((await page.locator('#whitelistList').textContent()).includes(f.agency))
      throw Error('Whitelist member not removed');
    console.log('PASS Browser operator whitelist mode and member add/remove');
    await choose(page, 'assignDepot', JSON.stringify([f.dimension, f.depot_id]));
    await choose(page, 'depotOperator', f.operator);
    await page.locator('#saveDepotOperator').click();
    await confirmDialog(page);
    await page.locator('#status').filter({ hasText: '车厂归属已保存' }).waitFor();
    if (!(await page.locator('#depotOwnerStatus').textContent()).includes(f.operator))
      throw Error('Depot owner not annotated');
    if (errors.length) throw Error(errors.join('\n'));
    await page.screenshot({ path: root + '/runtime/test-features.png', fullPage: true });
    console.log('PASS Browser depot ownership assignment/annotation; no JavaScript errors');
  } finally {
    if (browser) await browser.close();
    fixture('cleanup');
  }
})().catch((e) => {
  console.error(e.message);
  process.exitCode = 1;
});
