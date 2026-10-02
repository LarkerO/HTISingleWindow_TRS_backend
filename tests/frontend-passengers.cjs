'use strict';
const fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
let playwright;try{playwright=require('playwright');}catch{playwright=require('C:/Users/lxhor/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');}
const root=path.resolve(__dirname,'..'),suffix=require('node:crypto').randomBytes(5).toString('hex').toUpperCase();
function fixture(mode){
 const script=root+'/tests/passenger-fixture.php';let output;
 if(process.platform==='win32'&&root.startsWith('\\\\wsl.localhost\\Debian\\')){
  output=execFileSync('wsl',['-d','Debian','--','php',script.slice('\\\\wsl.localhost\\Debian'.length).replaceAll('\\','/'),mode,suffix],{encoding:'utf8'});
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
  await page.goto('http://localhost:8000/test.html');await page.locator('#apiKey').fill(key);await page.locator('#connect').click();
  await page.locator('#status').filter({hasText:'连接成功'}).waitFor();
  await page.locator('#documentsTab').click();
  await page.locator('#documentNumber').fill(f.document.document_number+'-EXTRA');
  await page.locator('#documentCountry').fill('CHN');await page.locator('#documentBirthDate').fill('1985-01-02');
  await page.locator('#documentGivenName').fill('页面新增旅客');
  await page.locator('#documentForm button[type=submit]').click();await page.locator('#status').filter({hasText:'证件已保存'}).waitFor();
  await page.locator('#docSearchNumber').fill(f.document.document_number);const lookup=page.waitForResponse(r=>r.url().includes('/passenger-documents?')&&r.url().includes('document_number='+f.document.document_number));await page.locator('#docSearch').click();await lookup;await page.locator('#docPageInfo').filter({hasText:'共 1 份证件'}).waitFor();
  const row=page.locator('#documentList tbody tr').filter({hasText:f.document.document_number});
  await row.getByRole('button',{name:'编辑',exact:true}).click();
  await page.locator('#documentGivenName').fill('页面更新旅客');await page.locator('#documentSurname').fill('李');await page.locator('#documentReason').fill('UI name correction');
  await page.locator('#documentForm button[type=submit]').click();await page.locator('#status').filter({hasText:'版本 2'}).waitFor();
  await row.getByRole('button',{name:'修改日志'}).click();
  await page.locator('#documentHistory').filter({hasText:'UI name correction'}).waitFor();
  const log=await page.locator('#documentHistory').textContent();
  if(!log.includes('旅客初始名')||!log.includes('页面更新旅客'))throw Error('Document history lacks before/after names');
  await row.getByRole('button',{name:'用于出票'}).click();
  if(await page.locator('#issueDocumentId').inputValue()!==String(f.document.id))throw Error('Document selection not linked to issuance');
  await page.locator('#agencyContext').selectOption(f.agency);
  await page.locator('#selectedJourney').filter({hasText:'出票方已切换'}).waitFor();
  await page.locator('#origin').selectOption(f.origin);await page.locator('#destination').selectOption(f.destination);await page.locator('#journeyDate').fill(f.date);
  await page.locator('#search').click();await page.locator('#status').filter({hasText:'查到'}).waitFor();
  await page.locator('#journeyResults tbody tr button').first().click();
  const issuedReply=page.waitForResponse(r=>r.url().endsWith('/api/v1/tickets')&&r.request().method()==='POST');
  await page.locator('#issue').click();const issued=(await (await issuedReply).json()).data;
  if(!issued||issued.passenger_document_snapshot?.version!==2||issued.passenger!=='李 页面更新旅客')throw Error('Document-based issue snapshot mismatch');
  await page.locator('#issuedTicket').filter({hasText:'证件快照'}).waitFor();
  console.log('PASS Browser document create/edit, before-after audit and snapshot-based issuance');
  await page.locator('#manageTab').click();await page.locator('#whitelistOperator').selectOption(f.operator);
  const rotation=page.waitForResponse(r=>r.url().endsWith('/admin/operators/'+f.operator+'/key'));
  page.once('dialog',dialog=>dialog.accept());await page.locator('#rotateOperatorKey').click();
  f.operator_key=(await (await rotation).json()).data.api_key;
  await page.locator('#status').filter({hasText:'运营方密钥已生成'}).waitFor();
  await page.locator('#disconnect').click();await page.locator('#apiKey').fill(f.operator_key);await page.locator('#connect').click();
  await page.locator('#status').filter({hasText:'运营方连接成功'}).waitFor();
  if(!await page.locator('#documentsTab').isHidden()||!await page.locator('#salesTab').isHidden())throw Error('Operator role exposes identity/sales panels');
  await page.locator('#operatorTicketList tbody tr').filter({hasText:issued.ticket_number}).getByRole('button').click();
  await page.locator('#operatorTicketInfo').filter({hasText:issued.ticket_number}).waitFor();
  await page.locator('#tlvTag').fill('101');await page.locator('#tlvLabel').fill('测试备注');await page.locator('#tlvValue').fill('中文');
  if(!(await page.locator('#tlvLength').textContent()).includes('6 字节'))throw Error('UTF-8 byte length UI wrong');
  await page.locator('#tlvForm button[type=submit]').click();await page.locator('#status').filter({hasText:'TLV 已追加'}).waitFor();
  await page.locator('#tlvEncoding').selectOption('hex');await page.locator('#tlvValue').fill('00FF80');
  await page.locator('#tlvForm button[type=submit]').click();
  await page.waitForFunction(()=>document.getElementById('tlvPageInfo').textContent.includes('2 条'));
  await page.locator('#tlvExport').click();await page.locator('#status').filter({hasText:'导出 2 条'}).waitFor();
  const wire=Buffer.from(await page.locator('#tlvWire').inputValue(),'base64');
  if(wire.length!==21||wire.readUInt16BE(0)!==101||wire.readUInt32BE(2)!==6||wire.subarray(6,12).toString('utf8')!=='中文')throw Error('Exported TLV binary header/value invalid');
  const textEntry=page.locator('#tlvList tbody tr').filter({hasText:'中文'});
  page.once('dialog',dialog=>dialog.accept('UI revocation reason'));await textEntry.getByRole('button',{name:'撤销',exact:true}).click();
  await page.locator('#status').filter({hasText:'附加信息已撤销'}).waitFor();await textEntry.getByRole('button',{name:'日志',exact:true}).click();
  await page.locator('#tlvHistory').filter({hasText:'UI revocation reason'}).waitFor();
  await page.locator('#tlvExport').click();await page.locator('#status').filter({hasText:'导出 1 条'}).waitFor();
  if(Buffer.from(await page.locator('#tlvWire').inputValue(),'base64').length!==9)throw Error('Revoked TLV included in active stream');
  if(errors.length)throw Error(errors.join('\n'));
  await page.screenshot({path:root+'/runtime/test-passengers-tlv.png',fullPage:true});
  console.log('PASS Browser operator key/role isolation, UTF-8 and binary TLV append, revoke, audit and stream export');
 }finally{if(browser)await browser.close();fixture('cleanup');}
})().catch(error=>{console.error(error.message);process.exitCode=1;});

