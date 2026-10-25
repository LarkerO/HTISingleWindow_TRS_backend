const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {createHash,createHmac}=require('node:crypto');
const {workbenchUrl,fillField}=require('./workbench-ui.cjs');
let playwright;try{playwright=require('playwright');}catch{playwright=require(process.env.PLAYWRIGHT_MODULE_PATH||'C:/Users/lxhor/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');}
(async()=>{
 const root=path.resolve(__dirname,'..'),key=fs.readFileSync(root+'/.env','utf8').match(/^TICKETING_API_KEY\s*=\s*(.+)$/m)[1].trim();
 const secret=createHash('sha256').update(key).digest('hex'),hash=v=>createHash('sm3').update(v).digest('hex');
 const browser=await playwright.chromium.launch({headless:true,channel:process.env.BROWSER_CHANNEL||'msedge'});
 try{
  const page=await browser.newPage({viewport:{width:1440,height:1000}}),errors=[],nonces=new Set();let signedCount=0;
  page.on('pageerror',e=>errors.push(e.message));
  page.on('request',r=>{if(!r.url().includes('/api/v1/'))return;try{
   const h=r.headers(),url=new URL(r.url());assert.equal(h['x-api-key'],undefined);assert.equal(h['x-api-key-id'],hash('MTR-KEY-V1\n'+secret));assert(!nonces.has(h['x-nonce']));nonces.add(h['x-nonce']);
   const canonical=['MTR-HMAC-SM3-V1',h['x-api-key-id'],r.method(),url.pathname+url.search,h['x-timestamp'],h['x-nonce'],(h['content-type']||'').trim().toLowerCase(),h['x-agency-code']||'',h['x-operator-code']||'',h['idempotency-key']||'',hash(r.postDataBuffer()||Buffer.alloc(0))].join('\n');
   assert.equal(h['x-signature'],createHmac('sm3',secret).update(canonical).digest('hex'));signedCount++;
  }catch(e){errors.push('Request signature mismatch: '+e.message);}});
  await page.goto(workbenchUrl);await fillField(page,'apiKey',key);await page.locator('#connect').click();await page.locator('#status').filter({hasText:'连接成功'}).waitFor();
  await page.locator('#manageTab').click();await page.locator('#management').waitFor({state:'visible'});
  assert.equal(await page.locator('#management [id^=fareRate]').count(),11);
  const policyText=await page.locator('#farePolicyInfo').textContent();assert(policyText.includes('单价版本'));
  const first=await page.locator('#fareRate2 input').inputValue();await fillField(page,'fareRate20',first);await page.locator('#saveFareRates').click();
  await page.locator('#status').filter({hasText:'Per-km rates must strictly increase with speed'}).waitFor();
  await page.locator('#reloadFareRates').click();await page.waitForResponse(r=>r.url().endsWith('/api/v1/admin/fare-rates')&&r.status()===200);
  const tamper=async route=>{const reply=await route.fetch();const data=await reply.json();data.data.currency='EVIL';await route.fulfill({response:reply,body:JSON.stringify(data)});};
  await page.route('**/api/v1/admin/fare-rates',tamper);await page.locator('#reloadFareRates').click();
  await page.locator('#status').filter({hasText:'响应 SM3 验签失败'}).waitFor();
  assert.equal(await page.locator('#farePolicyInfo').textContent(),policyText);
  await page.unroute('**/api/v1/admin/fare-rates',tamper);
  assert(signedCount>=8);assert.deepEqual(errors,[]);
  await page.screenshot({path:root+'/runtime/security-fares.png',fullPage:true});
  console.log('PASS Browser requests independently verified, unique nonces, no plaintext key, 11 fare controls, invalid rates rejected, tampered response rejected before use');
 }finally{await browser.close();}
})().catch(e=>{console.error(e.message);process.exitCode=1;});
