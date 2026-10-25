'use strict';
const workbenchUrl = process.env.WORKBENCH_URL || 'http://localhost:8000/test.html';
async function fillField(page, id, value) {
  const field = page.locator('#' + id),
    input = field.locator('input, textarea').first();
  await input.fill(String(value));
  const kind = await field.getAttribute('data-kind');
  if (kind === 'date') await input.press('Enter');
  if (['date', 'time', 'number'].includes(kind)) await input.press('Tab');
}
async function selectOptions(page, id) {
  const field = page.locator('#' + id);
  if ((await field.getAttribute('data-kind')) === 'radio')
    return field
      .locator('[data-option-value]')
      .evaluateAll((es) => es.map((e) => ({ value: e.getAttribute('data-option-value'), label: e.textContent })));
  await field.locator('input').first().click();
  const options = page.locator('.t-popup:visible [data-option-value]');
  await options.first().waitFor();
  const rows = await options.evaluateAll((es) =>
    es.map((e) => ({ value: e.getAttribute('data-option-value'), label: e.textContent })),
  );
  await page.keyboard.press('Escape');
  await page.locator('.page-heading h1').click();
  return rows;
}
async function choose(page, id, value) {
  const field = page.locator('#' + id),
    radio = (await field.getAttribute('data-kind')) === 'radio';
  if (!radio) await field.locator('input').first().click();
  const options = radio ? field.locator('[data-option-value]') : page.locator('.t-popup:visible [data-option-value]');
  await options.first().waitFor();
  let values = await options.evaluateAll((es) => es.map((e) => e.getAttribute('data-option-value')));
  let index = values.indexOf(String(value));
  if (index < 0 && !radio) {
    await field.locator('input').first().fill(String(value));
    await page.waitForTimeout(100);
    values = await options.evaluateAll((es) => es.map((e) => e.getAttribute('data-option-value')));
    index = values.indexOf(String(value));
  }
  if (index < 0) throw Error('Missing component option ' + id + ': ' + value);
  if (!radio) {
    const label = await options.nth(index).textContent();
    await field.locator('input').first().fill(label.trim());
    await page
      .locator('.t-popup:visible [data-option-value]:visible')
      .filter({ hasText: label.trim() })
      .first()
      .click();
  } else await options.nth(index).click();
}
async function confirmDialog(page) {
  const dialog = page.locator('.t-dialog:visible');
  await dialog.waitFor();
  await dialog.getByRole('button', { name: '确认', exact: true }).click();
}
async function reasonDialog(page, reason) {
  const dialog = page.locator('.t-dialog:visible');
  await dialog.waitFor();
  await dialog.locator('textarea').fill(reason);
  await dialog.getByRole('button', { name: '确认撤销', exact: true }).click();
}
function waitResponse(page, predicate) {
  const pending = page.waitForResponse(predicate);
  pending.catch(() => {});
  return pending;
}
module.exports = { waitResponse, workbenchUrl, fillField, choose, selectOptions, confirmDialog, reasonDialog };
