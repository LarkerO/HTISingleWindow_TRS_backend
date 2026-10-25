'use strict';
const {waitResponse,workbenchUrl,fillField,choose,selectOptions,confirmDialog,reasonDialog}=require('./workbench-ui.cjs');
const fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
let playwright;try{playwright=require('playwright');}catch{playwright=require('C:/Users/lxhor/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');}
const root=path.resolve(__dirname,'..'),suffix=require('node:crypto').randomBytes(5).toString('hex').toUpperCase();
function fixture(mode){
 const script=root+'/tests/passenger-fixture.php';let output;
 if(process.platform==='win32'&&(process.env.WSL_BACKEND_PATH||root.startsWith('\\\\wsl.localhost\\Debian\\'))){
  output=execFileSync('wsl',['-d','Debian','--','php',process.env.WSL_BACKEND_PATH?process.env.WSL_BACKEND_PATH+'/tests/passenger-fixture.php':script.slice('\\\\wsl.localhost\\Debian'.length).replaceAll('\\','/'),mode,suffix],{encoding:'utf8'});
 }else output=execFileSync('php',[script,mode,suffix],{encoding:'utf8'});
 return JSON.parse(output);
}
(async()=>{
 let browser;
 try{
  const f=fixture('setup'),key=fs.readFileSync(root+'/.env','utf8').match(/^TICKETING_API_KEY\s*=\s*(.+)$/m)[1].trim();
  browser=await playwright.chromium.launch({headless:true,channel:process.env.BROWSER_CHANNEL||'msedge'});
  const page=await browser.newPage({viewport:{width:1440,height:1100}}),errors=[];
  page.on('pageerror',error=>errors.push(error.message));
  await page.goto(workbenchUrl);await fillField(page,'apiKey',key);await page.locator('#connect').click();
  await page.locator('#status').filter({hasText:'连接成功'}).waitFor();
  await page.locator('#documentsTab').click();
  await fillField(page,'documentNumber',f.document.document_number+'-EXTRA');
  await fillField(page,'documentCountry','CHN');await fillField(page,'documentBirthDate','1985-01-02');
  await fillField(page,'documentGivenName','页面新增旅客');
  await page.locator('#documentForm button[type=submit]').click();await page.locator('#status').filter({hasText:'证件已保存'}).waitFor();
  await fillField(page,'docSearchNumber',f.document.document_number);const lookup=waitResponse(page,r=>r.url().includes('/passenger-documents?')&&r.url().includes('document_number='+f.document.document_number));await page.locator('#docSearch').click();await lookup;await page.locator('#docPageInfo').filter({hasText:'共 1 份证件'}).waitFor();
  const row=page.locator('#documentList tbody tr').filter({hasText:f.document.document_number});
  await row.getByRole('button',{name:'编辑',exact:true}).click();
  await fillField(page,'documentGivenName','页面更新旅客');await fillField(page,'documentSurname','李');await fillField(page,'documentReason','UI name correction');
  await page.locator('#documentForm button[type=submit]').click();await page.locator('#status').filter({hasText:'版本 2'}).waitFor();
  await row.getByRole('button',{name:'修改日志'}).click();
  await page.locator('#documentHistory').filter({hasText:'UI name correction'}).waitFor();
  const log=await page.locator('#documentHistory').textContent();
  if(!log.includes('旅客初始名')||!log.includes('页面更新旅客'))throw Error('Document history lacks before/after names');
  await row.getByRole('button',{name:'用于出票'}).click();
  if(await page.locator('#issueDocumentId input, #issueDocumentId textarea').first().inputValue()!==String(f.document.id))throw Error('Document selection not linked to issuance');
  await choose(page,'agencyContext',f.agency);
  await page.locator('#selectedJourney').filter({hasText:'出票方已切换'}).waitFor();
  await choose(page,'origin',f.origin);await choose(page,'destination',f.destination);await fillField(page,'journeyDate',f.date);
  await page.locator('#search').click();await page.locator('#status').filter({hasText:'查到'}).waitFor();
  await page.locator('#journeyResults tbody tr button').first().click();
  const issuedReply=waitResponse(page,r=>r.url().endsWith('/api/v1/tickets')&&r.request().method()==='POST');
  await page.locator('#issue').click();const issued=(await (await issuedReply).json()).data;
  if(!issued||issued.passenger_document_snapshot?.version!==2||issued.passenger!=='李 页面更新旅客')throw Error('Document-based issue snapshot mismatch');
  await page.locator('#issuedTicket').filter({hasText:'证件快照'}).waitFor();
  console.log('PASS Browser document create/edit, before-after audit and snapshot-based issuance');
  await page.locator('#manageTab').click();await choose(page,'whitelistOperator',f.operator);
  const rotation=waitResponse(page,r=>r.url().endsWith('/admin/operators/'+f.operator+'/key'));
  await page.locator('#rotateOperatorKey').click();await confirmDialog(page);
  f.operator_key=(await (await rotation).json()).data.api_key;
  await page.locator('#status').filter({hasText:'运营方密钥已生成'}).waitFor();
  await page.locator('#disconnect').click();await fillField(page,'apiKey',f.operator_key);await page.locator('#connect').click();
  await page.locator('#status').filter({hasText:'运营方连接成功'}).waitFor();
  if(!await page.locator('#documentsTab').isHidden()||!await page.locator('#salesTab').isHidden())throw Error('Operator role exposes identity/sales panels');
  await page.locator('#operatorTicketList tbody tr').filter({hasText:issued.ticket_number}).getByRole('button').click();
  await page.locator('#operatorTicketInfo').filter({hasText:issued.ticket_number}).waitFor();
  await fillField(page,'tlvTag','101');await fillField(page,'tlvLabel','测试备注');await fillField(page,'tlvValue','中文');
  if(!(await page.locator('#tlvLength').textContent()).includes('6 字节'))throw Error('UTF-8 byte length UI wrong');
  await page.locator('#tlvForm button[type=submit]').click();await page.locator('#status').filter({hasText:'TLV 已追加'}).waitFor();
  await choose(page,'tlvEncoding','hex');await fillField(page,'tlvValue','00FF80');
  await page.locator('#tlvForm button[type=submit]').click();
  await page.waitForFunction(()=>document.getElementById('tlvPageInfo').textContent.includes('2 条'));
  await page.locator('#tlvExport').click();await page.locator('#status').filter({hasText:'导出 2 条'}).waitFor();
  const wire=Buffer.from(await page.locator('#tlvWire input, #tlvWire textarea').first().inputValue(),'base64');
  if(wire.length!==21||wire.readUInt16BE(0)!==101||wire.readUInt32BE(2)!==6||wire.subarray(6,12).toString('utf8')!=='中文')throw Error('Exported TLV binary header/value invalid');
  const textEntry=page.locator('#tlvList tbody tr').filter({hasText:'中文'});
  await textEntry.getByRole('button',{name:'撤销',exact:true}).click();await reasonDialog(page,'UI revocation reason');
  await page.locator('#status').filter({hasText:'附加信息已撤销'}).waitFor();await textEntry.getByRole('button',{name:'日志',exact:true}).click();
  await page.locator('#tlvHistory').filter({hasText:'UI revocation reason'}).waitFor();
  await page.locator('#tlvExport').click();await page.locator('#status').filter({hasText:'导出 1 条'}).waitFor();
  if(Buffer.from(await page.locator('#tlvWire input, #tlvWire textarea').first().inputValue(),'base64').length!==9)throw Error('Revoked TLV included in active stream');
  if(errors.length)throw Error(errors.join('\n'));
  await page.screenshot({path:root+'/runtime/test-passengers-tlv.png',fullPage:true});
  console.log('PASS Browser operator key/role isolation, UTF-8 and binary TLV append, revoke, audit and stream export');
 }finally{if(browser)await browser.close();fixture('cleanup');}
})().catch(error=>{console.error(error.message);process.exitCode=1;});

