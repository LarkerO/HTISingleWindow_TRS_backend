'use strict';
const fs=require('node:fs');
let playwright;try{playwright=require('playwright');}catch{playwright=require('C:/Users/lxhor/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');}const {chromium}=playwright;
(async()=>{
 const root=require('node:path').resolve(__dirname,'..');
 const env=fs.readFileSync(root+'/.env','utf8');
 const key=env.match(/^TICKETING_API_KEY\s*=\s*(.+)$/m)?.[1].trim();
 if(!key)throw Error('Missing admin key');
 const browser=await chromium.launch({headless:true,channel:process.env.BROWSER_CHANNEL||'msedge'});
 try{
  const page=await browser.newPage({viewport:{width:1440,height:1000}});const errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  await page.goto('http://localhost:8000/test.html');
  await page.locator('#apiKey').fill(key);await page.locator('#connect').click();
  await page.locator('#status').filter({hasText:'连接成功'}).waitFor();
  if(await page.locator('#tripResults tbody tr').count()!==30)throw Error('Expected 30 trips');
  const stationReply=await page.request.get('http://localhost:8000/api/v1/stations',{headers:{'X-API-Key':key}});
  if(await page.locator('#origin option').count()!==(await stationReply.json()).data.length+1)throw Error('Expected all current stations');
  await page.locator('#next').click();await page.locator('#pageInfo').filter({hasText:'第 2 页'}).waitFor();
  if(await page.locator('#prev').isDisabled())throw Error('Previous button stuck disabled');
  await page.locator('#prev').click();await page.locator('#pageInfo').filter({hasText:'第 1 页'}).waitFor();
  const depotReply=await page.request.get('http://localhost:8000/api/v1/depots?date=2026-10-04',{headers:{'X-API-Key':key}});
  const depots=(await depotReply.json()).data;
  if(await page.locator('#tripDepot option').count()!==depots.length+1)throw Error('Incomplete depot dropdown');
  const zero=depots.find(d=>d.trip_count===0);
  const zeroReply=page.waitForResponse(r=>r.url().includes('/api/v1/trips?')&&r.url().includes('depot_id='));
  await page.locator('#tripDepot').selectOption(JSON.stringify([zero.dimension,zero.depot_id]));
  if((await (await zeroReply).json()).total!==0)throw Error('Zero-trip depot filter wrong');
  await page.locator('#depotInfo').filter({hasText:'无可售班次'}).waitFor();
  const limitDepot=depots.find(d=>d.sidings.some(s=>Number(s.max_trains)===0&&!s.unlimited_trains));
  const limitReply=page.waitForResponse(r=>r.url().includes('/api/v1/trips?')&&r.url().includes('depot_id='));
  await page.locator('#tripDepot').selectOption(JSON.stringify([limitDepot.dimension,limitDepot.depot_id]));await limitReply;
  await page.locator('#depotInfo details summary').click();
  await page.locator('#depotInfo pre').filter({hasText:'车辆上限 1（原始 max_trains=0）'}).first().waitFor();
  console.log('PASS Browser raw max_trains=0 displays one train, not disabled');
  const depot=depots.find(d=>Number(d.trip_count)>30)||depots[0];
  const filtered=page.waitForResponse(r=>r.url().includes('/api/v1/trips?')&&r.url().includes('depot_id='));
  await page.locator('#tripDepot').selectOption(JSON.stringify([depot.dimension,depot.depot_id]));
  const runs=(await (await filtered).json()).data;
  if(!runs.length||runs.some(t=>t.depot_id!==depot.depot_id||t.dimension!==depot.dimension||t.trip_code!==`${t.depot_id}+${t.siding_id}+${t.run_number}`))throw Error('Depot UI filter or composite code incorrect');
  await page.locator('#tripResults tbody tr code').first().filter({hasText:runs[0].trip_code}).waitFor();
  if(runs[0].run_number!==1)throw Error('Daily run number must start at one');
  const databaseMode=page.waitForResponse(r=>r.url().includes('/api/v1/trips?')&&r.url().includes('depot_id='));
  await page.locator('#tripIdMode').selectOption('database');await databaseMode;
  await page.locator('#tripResults tbody tr code').first().filter({hasText:String(runs[0].id)}).waitFor();
  const compoundMode=page.waitForResponse(r=>r.url().includes('/api/v1/trips?')&&r.url().includes('depot_id='));
  await page.locator('#tripIdMode').selectOption('compound');await compoundMode;
  await page.locator('#tripResults tbody tr code').first().filter({hasText:runs[0].trip_code}).waitFor();
  console.log('PASS Browser MTR depot filtering and composite/database ID display modes');
  await page.locator('#tripResults tbody tr').first().getByRole('button').click();
  await page.locator('#tripDetail').waitFor({state:'visible'});
  await page.locator('#tripDetail').getByRole('button',{name:'查询此区间'}).click();
  await page.screenshot({path:root+'/runtime/test-query.png',fullPage:true});
  await page.locator('#journeyResults tbody tr button').first().waitFor();
  await page.locator('#journeyResults tbody tr button').first().click();
  if(await page.locator('#issue').isDisabled())throw Error('Journey not selected');
  if(!await page.locator('#requestKey').inputValue())throw Error('No idempotency key');
  await page.screenshot({path:root+'/runtime/test-sales.png',fullPage:true});
  await page.locator('#manageTab').click();await page.locator('#operatorsList tbody tr').first().getByRole('button').click();
  if(!await page.locator('#opCode').isDisabled())throw Error('Operator code should be immutable');
  await page.locator('#resetOperator').click();if(await page.locator('#opCode').isDisabled())throw Error('Operator form reset failed');
  await page.locator('#agenciesList tbody tr').first().getByRole('button').click();
  if(!await page.locator('#agIssuer').isDisabled())throw Error('Agency prefix should be immutable');
  await page.locator('#resetAgency').click();if(await page.locator('#agIssuer').isDisabled())throw Error('Agency form reset failed');

  await page.locator('#clearPreview').click();
  await page.locator('#status').filter({hasText:'已预览清空范围'}).waitFor();
  if(!await page.locator('#clearSummary').textContent())throw Error('Cleanup counts missing');
  await page.locator('#clearScope').selectOption('timetable');
  if(!await page.locator('#clearExecute').isDisabled())throw Error('Changed cleanup scope must invalidate preview');
  await page.locator('#clearPreview').click();
  await page.locator('#status').filter({hasText:'已预览清空范围'}).waitFor();
  const cleanup=await page.request.post('http://localhost:8000/api/v1/admin/clear-date',{headers:{'X-API-Key':key},data:{date:'2026-10-04',scope:'timetable',preview:true}});
  if(await page.locator('#clearExecute').isDisabled()!==(await cleanup.json()).data.blocked)throw Error('Timetable ticket block not reflected in UI');
  console.log('PASS Browser date-cleanup counts, stale-preview reset and ticket protection');

  await page.screenshot({path:root+'/runtime/test-management.png',fullPage:true});
  await page.locator('#disconnect').click();if(await page.locator('#apiKey').inputValue())throw Error('Key not cleared');
  if(errors.length)throw Error(errors.join('\n'));
  console.log('PASS Browser connection, complete current stations and all depots, pagination, interval selection, forms, key clearing; no JavaScript errors');
  await page.setViewportSize({width:390,height:844});await page.screenshot({path:root+'/runtime/test-mobile.png',fullPage:true});
 }finally{await browser.close();}
})().catch(e=>{console.error(e.message);process.exitCode=1;});








