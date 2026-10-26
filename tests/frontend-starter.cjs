'use strict';
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
  path = require('node:path');
const {
  chromium,
} = require('C:/Users/lxhor/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
(async () => {
  const root = path.resolve(__dirname, '..');
  const key = fs
    .readFileSync(path.join(root, '.env'), 'utf8')
    .match(/^TICKETING_API_KEY\s*=\s*(.+)$/m)[1]
    .trim();
  const browser = await chromium.launch({ headless: true, channel: process.env.BROWSER_CHANNEL || 'msedge' });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } }),
      errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('console', (msg) => {
      if (msg.type() === 'warning' || msg.type() === 'error') errors.push(msg.text());
    });
    await page.goto(workbenchUrl);
    await page.locator('.starter-menu').waitFor();
    const duplicateIds = await page.evaluate(() => {
      const elements = [...document.querySelectorAll('[id]')];
      const ids = elements.filter((e) => !e.closest('svg')).map((e) => e.id);
      return ids.filter((id) => elements.filter((e) => e.id === id).length !== 1);
    });
    if (duplicateIds.length) throw Error('Duplicate element IDs: ' + duplicateIds.join(','));
    if (await page.locator('#manageTab').isVisible()) throw Error('Admin menu visible before authentication');
    await page.getByRole('button', { name: '切换明暗主题' }).click();
    if ((await page.locator('html').getAttribute('theme-mode')) !== 'dark') throw Error('Dark theme failed');
    await fillField(page, 'apiKey', key);
    await page.locator('#connect').click();
    await page.locator('#status').filter({ hasText: '连接成功' }).waitFor();
    await page.locator('#operatorsTab').click();
    await page.waitForURL('**#/operators');
    await page.locator('#operators').waitFor({ state: 'visible' });
    const clippedButtons = await page
      .locator('#operators .row > button')
      .evaluateAll((es) => es.filter((e) => e.scrollWidth > e.clientWidth + 1).map((e) => e.textContent));
    if (clippedButtons.length) throw Error('Clipped form buttons: ' + clippedButtons.join(','));
    if (await page.locator('#sales').isVisible()) throw Error('Inactive page visible');
    await page.screenshot({ path: path.join(root, 'runtime/tdesign-management.png'), fullPage: true });
    await page.locator('#salesTab').click();
    await page.waitForURL('**#/sales');
    await page.screenshot({ path: path.join(root, 'runtime/tdesign-sales.png'), fullPage: true });
    if ((await page.locator('#tripResults tbody tr').count()) !== 30) throw Error('Navigation cleared loaded data');
    await page.getByRole('button', { name: '切换明暗主题' }).click();
    await page.getByRole('button', { name: '收起或展开导航' }).click();
    await page.locator('#ticketsTab .t-menu__item').click();
    await page.waitForURL('**#/tickets');
    await page.locator('#documentsTab').focus();
    await page.locator('#documentsTab').press('Enter');
    await page.waitForURL('**#/documents');
    await page.locator('#disconnect').click();
    if (await page.locator('#apiKey input, #apiKey textarea').first().inputValue())
      throw Error('Key remains after disconnect');
    if ((await page.locator('#manageTab').isVisible()) || (await page.locator('#operatorTicketsTab').isVisible()))
      throw Error('Privileged menu remains after disconnect');
    await page.reload();
    if (await page.locator('#apiKey input, #apiKey textarea').first().inputValue())
      throw Error('Key persisted across reload');
    await page.goto(process.env.WORKBENCH_URL || 'http://localhost:8000/workbench/index.html#/sales');
    await page.locator('#apiKey').waitFor();
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: path.join(root, 'runtime/tdesign-mobile.png'), fullPage: true });
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    if (overflow) throw Error('Mobile page horizontally overflows');
    if (errors.length) throw Error(errors.join('\n'));
    console.log(
      'PASS Starter navigation, retained page state, themes, key clearing, refresh, direct entry, mobile layout; no browser errors',
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
