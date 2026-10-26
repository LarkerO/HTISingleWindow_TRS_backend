'use strict';
const fs = require('node:fs'),
  path = require('node:path');
const {
  chromium,
} = require('C:/Users/lxhor/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const { workbenchUrl, fillField } = require('./workbench-ui.cjs');
(async () => {
  const root = path.resolve(__dirname, '..');
  const key = fs
    .readFileSync(path.join(root, '.env'), 'utf8')
    .match(/^TICKETING_API_KEY\s*=\s*(.+)$/m)[1]
    .trim();
  const browser = await chromium.launch({ headless: true, channel: process.env.BROWSER_CHANNEL || 'msedge' });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } }),
      errors = [],
      writes = [];
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('request', (r) => {
      if (r.url().includes('/api/v1/') && r.method() !== 'GET')
        writes.push(r.method() + ' ' + new URL(r.url()).pathname);
    });
    await page.goto(workbenchUrl);
    if (await page.locator('#manageTab').isVisible()) throw Error('Unauthenticated administration visible');
    await fillField(page, 'apiKey', key);
    await page.locator('#connect').click();
    await page.locator('#status').filter({ hasText: '连接成功' }).waitFor({ timeout: 30000 });
    async function visit(id) {
      await page.locator('#' + id + 'Tab').click();
      await page.waitForURL('**#/' + id);
      await page.locator('#' + id).waitFor({ state: 'visible' });
      const visible = await page.locator('.panel:visible').count();
      if (visible !== 1) throw Error('Multiple management pages visible');
    }
    await visit('operators');
    if (await page.locator('#operatorEditor').isVisible()) throw Error('Editor should start closed');
    await fillField(page, 'operatorSearch', 'NO_MATCH_' + Date.now());
    if (await page.locator('#operatorsList tbody tr').getByRole('button', { name: '编辑', exact: true }).count())
      throw Error('Operator filter ignored');
    await fillField(page, 'operatorSearch', '');
    await page.locator('#operatorsList').getByRole('button', { name: '编辑', exact: true }).first().click();
    await page.locator('#operatorEditor').waitFor({ state: 'visible' });
    if (!(await page.locator('#opCode input').isDisabled())) throw Error('Existing code is editable');
    await page.locator('#resetOperator').click();
    await page.locator('#operatorEditor').waitFor({ state: 'hidden' });
    await page.locator('#newOperator').click();
    if (await page.locator('#opCode input').isDisabled()) throw Error('New code is disabled');
    await page.locator('#resetOperator').click();
    await visit('agencies');
    await page.locator('#newAgency').click();
    await page.locator('#agencyEditor').waitFor({ state: 'visible' });
    await page.locator('#resetAgency').click();
    await visit('agencyGrants');
    await page.locator('#grantAgency').waitFor({ state: 'visible' });
    await visit('whitelist');
    await page.locator('#whitelistList').waitFor({ state: 'visible' });
    await visit('assignments');
    if (!(await page.locator('#depotOwnershipList .t-table').isVisible())) throw Error('Depot list missing');
    await fillField(page, 'depotOwnerSearch', 'NO_MATCH_' + Date.now());
    if (await page.locator('#depotOwnershipList').getByRole('button', { name: '设置归属', exact: true }).count())
      throw Error('Depot filter ignored');
    await fillField(page, 'depotOwnerSearch', '');
    await visit('stationDictionary');
    await page.locator('#dictionaryList').getByRole('button', { name: '编辑', exact: true }).first().click();
    await page.locator('#dictionaryEditor').waitFor({ state: 'visible' });
    await page.locator('#dictCancel').click();
    await page.locator('#dictionaryEditor').waitFor({ state: 'hidden' });
    await visit('fareRates');
    if ((await page.locator('#fareRates tbody tr').count()) !== 11) throw Error('Missing speed-rate rows');
    await page.getByText('数据维护', { exact: true }).click();
    await visit('dataCleanup');
    await page.locator('#clearPreview').waitFor({ state: 'visible' });
    await page.locator('#stockTab').click();
    await page.locator('#rollingStock').waitFor({ state: 'visible' });
    await page.locator('#operatorsTab').click();
    if ((await page.locator('.starter-aside').evaluate((e) => e.getBoundingClientRect().width)) < 180)
      await page.getByRole('button', { name: '收起或展开导航' }).click();
    await page.screenshot({ path: path.join(root, 'runtime/management-pages.png'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: path.join(root, 'runtime/management-pages-mobile.png'), fullPage: true });
    if (await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth))
      throw Error('Mobile overflow');
    await page.locator('#disconnect').click();
    await page.locator('#manageTab').waitFor({ state: 'hidden' });
    await page.goto(workbenchUrl + '#/fareRates');
    await page.waitForURL('**#/sales');
    if (writes.length) throw Error('Navigation/edit previews sent writes: ' + writes.join(','));
    if (errors.length) throw Error(errors.join('\n'));
    console.log(
      'PASS Two-level menus, eight independent management pages, list filters, edit/create panels, fare list, retained stock module, mobile layout and protected direct routes; no writes or browser errors',
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
