'use strict';
const fs = require('node:fs'),
  path = require('node:path');
const { workbenchUrl, fillField } = require('./workbench-ui.cjs');
let playwright;
try {
  playwright = require('playwright');
} catch {
  playwright = require('C:/Users/lxhor/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
}
(async () => {
  const root = path.resolve(__dirname, '..'),
    key = fs
      .readFileSync(root + '/.env', 'utf8')
      .match(/^TICKETING_API_KEY\s*=\s*(.+)$/m)[1]
      .trim();
  const browser = await playwright.chromium.launch({
    headless: true,
    channel: process.env.BROWSER_CHANNEL || 'msedge',
  });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } }),
      errors = [],
      writes = [];
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('dialog', async (dialog) => {
      errors.push('Unexpected native browser dialog');
      await dialog.dismiss();
    });
    page.on('request', (r) => {
      if (r.url().includes('/api/v1/') && r.method() !== 'GET') writes.push(r.url());
    });
    await page.goto(workbenchUrl);
    await fillField(page, 'apiKey', key);
    await page.locator('#connect').click();
    await page.locator('#status').filter({ hasText: '连接成功' }).waitFor();
    if (await page.locator('select,input[type=date],input[type=time],input[type=number]').count())
      throw Error('Native select/date/time/number control remains');
    await page.locator('#origin input').click();
    await page.locator('#origin input').fill('广阳');
    const options = page.locator('.t-popup:visible [data-option-value]');
    await options.first().waitFor();
    const labels = await options.allTextContents();
    if (!labels.length || labels.some((label) => !label.includes('广阳')))
      throw Error('Searchable station selector does not filter');
    await page.locator('.page-heading h1').click();
    await page.locator('#journeyDate input').click();
    await page.locator('.t-date-picker__panel:visible').waitFor();
    await page.locator('.page-heading h1').click();
    await page.locator('#journeyTimeFrom input').click();
    await page.locator('.t-time-picker__panel:visible').waitFor();
    await page.locator('.page-heading h1').click();
    if (!(await page.locator('#tripIdMode .t-radio-group').isVisible())) throw Error('TDesign radio group missing');
    await page.locator('#operatorsTab').click();
    await page.locator('#operators').waitFor({ state: 'visible' });
    if (!(await page.locator('#operatorsList .t-table').isVisible())) throw Error('TDesign operator table missing');
    await page.locator('#agencyGrantsTab').click();
    const checkbox = page.locator('#grantOperators .t-checkbox').first(),
      native = checkbox.locator('input');
    const checked = await native.isChecked();
    await checkbox.click();
    if ((await native.isChecked()) === checked) throw Error('Checkbox state did not change');
    await checkbox.click();
    await page.locator('#rotateKey').click();
    await page.locator('.t-dialog:visible').getByRole('button', { name: '取消', exact: true }).click();
    await page.locator('.t-dialog:visible').waitFor({ state: 'hidden' });
    await page.waitForFunction(() => !document.getElementById('rotateKey').disabled);
    await page.locator('#operatorsTab').click();
    await page.locator('#newOperator').click();
    await page.locator('#operatorFormSubmit').click();
    await page.locator('#operatorForm').getByText('请填写名称', { exact: true }).waitFor();
    await page.locator('#documentsTab').click();
    await fillField(page, 'documentCountry', 'CN');
    await page.locator('#documentFormSubmit').click();
    await page.locator('#documentForm').getByText('证件归属地（三字母码）格式不正确', { exact: true }).waitFor();
    if (writes.length) throw Error('Cancelled/invalid operations sent writes: ' + writes.join(','));
    if (errors.length) throw Error(errors.join('\n'));
    console.log(
      'PASS TDesign search, date/time panels, radio/checkbox groups, tables, validation and cancellation; no native dialogs or write requests',
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
