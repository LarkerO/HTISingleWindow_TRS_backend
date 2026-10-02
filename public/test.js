'use strict';
const $ = id => document.getElementById(id);
let state={key:'',identity:null,operators:[],agencies:[],stations:new Map(),depots:[],journey:null,page:1,total:0,pageSize:30,opEdit:null,agEdit:null,documentEdit:null,documentHistoryId:null,documentHistoryPage:1,documentHistoryTotal:0,docPage:1,docTotal:0,operatorPage:1,operatorTotal:0,tlvPage:1,tlvTotal:0};
function message(text,error=false){$('status').textContent=text;$('status').classList.toggle('error',error);$('status').hidden=false;}
async function api(path,method='GET',body,extra={}){
  if(!state.key)throw Error('请先输入密钥并连接');
  const headers={'X-API-Key':state.key,...extra};
  if(state.identity?.role==='admin'&&$('agencyContext').value)headers['X-Agency-Code']=$('agencyContext').value;
  if(state.identity?.role==='admin'&&path.startsWith('/operator/')&&$('operatorScope').value)headers['X-Operator-Code']=$('operatorScope').value;
  if(body!==undefined)headers['Content-Type']='application/json';
  const response=await fetch('/api/v1'+path,{method,headers,body:body===undefined?undefined:JSON.stringify(body)});
  const data=await response.json().catch(()=>({error:'服务器未返回 JSON'}));
  if(!response.ok)throw Error(`${response.status} · ${data.error||'请求失败'}`);
  return data;
}
function on(id,fn,event='click'){$(id).addEventListener(event,async e=>{e.preventDefault();const source=e.currentTarget;if(source.dataset.busy)return;source.dataset.busy='1';const button=event==='submit'?source.querySelector('button[type=submit]'):source;const disabled=button.disabled;button.disabled=true;try{await fn(e);}catch(err){message(err.message,true);}finally{source.dataset.busy='';button.disabled=disabled;if(id==='prev')button.disabled=state.page<=1;if(id==='next')button.disabled=state.page*state.pageSize>=state.total;if(id==='clearExecute')button.disabled=!cleanupPreview||cleanupPreview.blocked;if(id==='docPrev')button.disabled=state.docPage<=1;if(id==='docNext')button.disabled=state.docPage*30>=state.docTotal;if(id==='operatorPrev')button.disabled=state.operatorPage<=1;if(id==='operatorNext')button.disabled=state.operatorPage*30>=state.operatorTotal;if(id==='tlvPrev')button.disabled=state.tlvPage<=1;if(id==='tlvNext')button.disabled=state.tlvPage*30>=state.tlvTotal;if(id==='documentHistoryPrev')button.disabled=!state.documentHistoryId||state.documentHistoryPage<=1;if(id==='documentHistoryNext')button.disabled=!state.documentHistoryId||state.documentHistoryPage*30>=state.documentHistoryTotal;}});}
function el(tag,text){const n=document.createElement(tag);if(text!==undefined)n.textContent=String(text);return n;}
function action(label,fn,secondary=true){const b=el('button',label);if(secondary)b.className='secondary';b.onclick=async()=>{b.disabled=true;try{await fn();}catch(e){message(e.message,true);}finally{b.disabled=false;}};return b;}
function table(target,head,rows){const t=el('table'),tr=el('tr'),thead=el('thead');head.forEach(h=>tr.append(el('th',h)));thead.append(tr);t.append(thead);const body=el('tbody');rows.forEach(cells=>{const r=el('tr');cells.forEach(c=>{const td=el('td');td.append(c instanceof Node?c:document.createTextNode(String(c??'')));r.append(td);});body.append(r);});if(!rows.length){const r=el('tr'),td=el('td','暂无数据');td.colSpan=head.length;r.append(td);body.append(r);}t.append(body);$(target).replaceChildren(t);}
function choices(id,rows,value,label,placeholder){const old=$(id).value;$(id).replaceChildren();if(placeholder!==undefined)$(id).append(new Option(placeholder,''));rows.forEach(r=>$(id).append(new Option(label(r),String(value(r)))));if([...$(id).options].some(o=>o.value===old))$(id).value=old;}
function station(id){const s=state.stations.get(id);return s?s.name.split('|')[0]:id;}
function money(n,currency='CNY'){return `${(Number(n)/100).toFixed(2)} ${currency}`;}
function newKey(){ $('requestKey').value=globalThis.crypto?.randomUUID?.()||`test-${Date.now()}-${Math.random().toString(36).slice(2)}`; }
function tab(name){document.querySelectorAll('.panel').forEach(p=>p.hidden=p.id!==name);document.querySelectorAll('[data-tab]').forEach(b=>b.classList.toggle('active',b.dataset.tab===name));}
document.querySelectorAll('[data-tab]').forEach(b=>b.onclick=()=>tab(b.dataset.tab));
async function connect(){
  state.key=$('apiKey').value.trim();state.identity=null;
  const result=await api('/me');state.identity=result.data;
  const admin=state.identity.role==='admin',operator=state.identity.role==='operator';
  $('identity').textContent=operator?`身份：运营方 · ${state.identity.operator_code} · ${state.identity.operator?.name||''}`:`身份：${admin?'管理员':'出票方'} · 当前出票方 ${state.identity.agency_code} · ${state.identity.agency?.name||'未配置'}`;
  $('manageTab').hidden=!admin;$('agencyContext').disabled=!admin;
  $('salesTab').hidden=operator;$('ticketsTab').hidden=operator;$('documentsTab').hidden=operator;$('operatorTicketsTab').hidden=!(admin||operator);
  state.journey=null;$('issue').disabled=true;$('selectedJourney').textContent='先在查询结果中选择车次。';
  state.docPage=1;state.operatorPage=1;state.tlvPage=1;resetDocument();
  $('operatorScope').disabled=operator;
  if(operator){
    choices('operatorScope',[state.identity.operator],o=>o.code,o=>`${o.name} (${o.code})`);
    tab('operatorTickets');await browseOperatorTickets();message('运营方连接成功');return;
  }
  if(!admin){
    $('agencyContext').replaceChildren(new Option(state.identity.agency_code,''));
    if(!$('management').hidden||!$('operatorTickets').hidden)tab('sales');
  }
  const stations=(await api('/stations')).data;state.stations=new Map(stations.map(s=>[s.id,s]));
  choices('origin',stations,s=>s.id,s=>`${s.name.split('|')[0]} · ${s.dimension}`,'请选择出发站');choices('destination',stations,s=>s.id,s=>`${s.name.split('|')[0]} · ${s.dimension}`,'请选择到达站');
  if(admin)await management();
  await loadDepots();await browse();await loadDocuments();message('连接成功，已加载车站、班次与证件库');
}
function tripLabel(t){return $('tripIdMode').value==='database'?String(t.id):(t.trip_code||String(t.id));}
function tripCell(t){const cell=el('div'),code=el('code',tripLabel(t));if(t.train_number)cell.append(el('strong',t.train_number));if(t.duty){const duty=el('div',t.duty);duty.className='muted';cell.append(duty);}code.className='trip-code';cell.append(code);const caption=el('div',`${t.name.split('|')[0]} · 第 ${t.run_number} 趟 · 数据库 ID ${t.id}`);caption.className='muted';cell.append(caption);return cell;}
function depotReason(reason){
 if(reason==='No configured routes')return '尚未配置线路';
 if(reason==='No generated path')return '股道没有已生成的运行路径，需要在 MTR 中生成路径';
 if(reason.includes('eligible count=0'))return '车厂区域内没有保存的股道，请检查存档中的股道关联';
 if(reason.startsWith('Select one planned siding'))return '旧导入记录尚未更新，请重新导入未出票的服务日期';
 if(reason.startsWith('Infinite'))return '无限循环运行尚未配置循环展开规则';
 if(reason.startsWith('Frequency'))return '游戏时间频率发车缺少世界时钟锚点（world-tick）';
 if(reason.startsWith('Unsupported'))return '手动驾驶或当前未支持的交通模式';
 if(reason.startsWith('Ambiguous'))return '站台车站区域重叠，需要确认车站归属';
 if(reason==='No departures')return '没有配置发车时刻';
 return reason||'当前日期或运营方筛选下没有已入库班次';
}
function depotInfo(){
 const box=$('depotInfo');box.replaceChildren();
 if(!$('tripDepot').value){box.append(el('p',`完整地图目录：${state.depots.length} 个车厂。未配置或暂时无法生成班次的车厂同样保留；选择车厂可查看设置、股道和原因。`));return;}
 const [dimension,id]=JSON.parse($('tripDepot').value),d=state.depots.find(d=>d.dimension===dimension&&d.depot_id===id);if(!d)return;
 box.append(el('p',d.operator_code?`车厂归属运营方：${d.operator_name} (${d.operator_code}) · 全部线路、班次及后续导入`:'尚未划归运营方，班次采用导入或单班次设置'));box.append(el('h3',`${d.name} · ${d.depot_id}`),el('p',`${dimension} · ${d.transport_mode} · ${d.use_real_time?'实时时刻表':'游戏时间频率'} · ${d.repeat_infinitely?'无限循环':'有限运行'} · ${d.route_ids.length} 条线路 · ${d.siding_count} 条股道`));
 box.append(el('p',d.trip_count?`筛选条件下已入库 ${d.trip_count} 趟班次。`:`无可售班次：${depotReason(d.planning_reason)}。`));
 if(d.planning_reason){const reason=el('p',`当前原始数据诊断：${depotReason(d.planning_reason)}（${d.planning_reason}）`);reason.className='muted';box.append(reason);}
 if(d.siding_count>1)box.append(el('p','多股道按名称、颜色顺序轮换生成计划班次，每个发车时刻只分配一次；实际发车股道取决于游戏内列车就绪情况。mapping 可用于指定股道。'));
 const details=el('details');details.append(el('summary','查看全部股道与发车配置'));
 const list=el('pre');list.textContent=d.sidings.map(s=>`${s.name} · 股道 ${s.siding_id} · ${s.path_segments} 段路径 · 车辆上限 ${s.unlimited_trains?'无限':s.vehicle_limit}（原始 max_trains=${s.max_trains}）${s.is_manual?' · 手动驾驶':''}`).join('\n')||'该车厂范围内没有保存的股道';details.append(list);
 details.append(el('pre',JSON.stringify({route_ids:d.route_ids,departures_ms:d.departures,frequencies:d.frequencies},null,2)));box.append(details);
}
async function loadDepots(){
  const q=new URLSearchParams();if($('tripDate').value)q.set('date',$('tripDate').value);if($('tripOperator').value)q.set('operator',$('tripOperator').value);
  const depots=(await api('/depots?'+q)).data;state.depots=depots;
  if(state.identity?.role==='admin'){choices('assignDepot',depots,d=>JSON.stringify([d.dimension,d.depot_id]),d=>`${d.name} · ${d.depot_id} · ${d.operator_code||'未划归'}`);depotOwnerStatus();}
  choices('tripDepot',depots,d=>JSON.stringify([d.dimension,d.depot_id]),d=>`${d.name} · ${d.depot_id} · ${d.trip_count} 趟${d.trip_count===0?'（未生成当日班次）':''} · ${d.dimension}`,'全部车厂');
}
async function browse(){
  const q=new URLSearchParams({page:state.page});if($('tripDate').value)q.set('date',$('tripDate').value);if($('tripOperator').value)q.set('operator',$('tripOperator').value);
  for(const [key,id] of [['train_number','tripTrainNumber'],['time_from','tripTimeFrom'],['time_to','tripTimeTo']])if($(id).value)q.set(key,$(id).value);
  if($('tripDepot').value){const [dimension,depot]=JSON.parse($('tripDepot').value);q.set('dimension',dimension);q.set('depot_id',depot);}
  depotInfo();
  const result=await api('/trips?'+q);state.total=result.total;state.pageSize=result.page_size;$('pageInfo').textContent=`共 ${result.total} 趟 · 第 ${result.page} 页 / ${Math.max(1,Math.ceil(result.total/result.page_size))} 页`;
  $('prev').disabled=state.page<=1;$('next').disabled=state.page*result.page_size>=result.total;
  table('tripResults',['班次 ID / 名称','运营方','区间','发车（UTC）','操作'],result.data.map(t=>[tripCell(t),`${t.operator_name} (${t.operator_code})${t.operator_assignment==='depot'?' · 车厂归属':''}`,`${station(t.origin?.station_id)} → ${station(t.destination?.station_id)}`,t.origin?.departure_at,action('查看 / 选区间',()=>detail(t.id))]));
}
async function detail(id){
  const t=(await api('/trips/'+id)).data;$('assignTripId').value=t.id;
  const box=$('tripDetail');box.hidden=false;box.replaceChildren(el('h3',`${t.train_number||'班次'} · ${tripLabel(t)} · 第 ${t.run_number} 趟 · ${t.name.split('|')[0]}（数据库 ID ${t.id}）${t.operator_assignment==='depot'?' · 车厂归属运营方 '+t.operator_code:''}`));
  if(t.duty)box.append(el('p',t.duty));
  const a=el('select'),b=el('select');t.stops.forEach(s=>{a.append(new Option(`${s.seq} · ${station(s.station_id)}`,s.seq));b.append(new Option(`${s.seq} · ${station(s.station_id)}`,s.seq));});b.value=([...t.stops].reverse().find(s=>s.station_id!==t.stops[0].station_id)||t.stops.at(-1)).seq;
  const row=el('div');row.className='row';const la=el('label','上车站'),lb=el('label','下车站');la.append(a);lb.append(b);row.append(la,lb,action('查询此区间',async()=>{const from=t.stops.find(s=>s.seq==a.value),to=t.stops.find(s=>s.seq==b.value);if(Number(a.value)>=Number(b.value))throw Error('下车站序必须大于上车站序');$('origin').value=from.station_id;$('destination').value=to.station_id;$('journeyDate').value=from.departure_at.slice(0,10);$('journeyTrainNumber').value=t.train_number||'';$('journeyTimeFrom').value='';$('journeyTimeTo').value='';await search();$('journeyResults').scrollIntoView({behavior:'smooth',block:'center'});}));box.append(row);
  const list=el('pre');list.textContent=t.stops.map(s=>`${s.seq} · ${station(s.station_id)} · 到 ${s.arrival_at} / 发 ${s.departure_at}`).join('\n');box.append(list);
}
async function search(){state.journey=null;$('issue').disabled=true;$('selectedJourney').textContent='请从新查询结果选择车次。';const q=new URLSearchParams({origin:$('origin').value,destination:$('destination').value,date:$('journeyDate').value});for(const [key,id] of [['train_number','journeyTrainNumber'],['time_from','journeyTimeFrom'],['time_to','journeyTimeTo']])if($(id).value)q.set(key,$(id).value);const rows=(await api('/journeys?'+q)).data;
  table('journeyResults',['班次','运营方','发车 → 到达（UTC）','余票','票价','操作'],rows.map(t=>{const b=action('选择出票',()=>{state.journey=t;newKey();$('selectedJourney').textContent=`${t.train_number||'班次'} · ${tripLabel(t)} · 数据库 ID ${t.id} · ${station($('origin').value)} → ${station($('destination').value)} · ${t.departure_at} → ${t.arrival_at} UTC · 站序 ${t.origin_seq}–${t.destination_seq} · ${money(t.amount_minor,t.currency)}`;$('issue').disabled=false;$('issueSection').scrollIntoView({behavior:'smooth'});});b.disabled=t.available<1;return [tripCell(t),t.operator_code+(t.operator_assignment==='depot'?' · 车厂归属':''),`${t.departure_at} → ${t.arrival_at}`,t.available,money(t.amount_minor,t.currency),b];}));message(`查到 ${rows.length} 个可选区间`);
}
function renderTicket(target,t){const box=el('div');box.className='ticket';box.append(el('strong',t.ticket_number),el('p',`PNR ${t.pnr} · ${t.status==='OPEN'?'有效':'已退票'} · ${t.passenger} · ${money(t.amount_minor,t.currency)}`),el('p',`出票方 ${t.agency_code} · ${t.issued_at} UTC`));(t.coupons||[]).forEach(c=>box.append(el('p',`${c.trip.train_number||'班次'} · ${tripLabel(c.trip)} · 数据库 ID ${c.trip.id} · 运营方 ${c.trip.operator_code} · ${station(c.origin.station_id)} → ${station(c.destination.station_id)} · ${c.origin.departure_at} UTC`)));if(t.passenger_document_snapshot){const d=t.passenger_document_snapshot;box.append(el('p',`证件快照：${d.document_type} · ${d.document_number} · ${d.issuing_country} · ${d.display_name} · 出生 ${d.birth_date} · 版本 ${d.version}`));}const d=el('details');d.append(el('summary','原始响应'));d.append(el('pre',JSON.stringify(t,null,2)));box.append(d);$(target).replaceChildren(box);$('ticketNumber').value=t.ticket_number;}
async function management(){
  const [ops,ags]=await Promise.all([api('/admin/operators'),api('/admin/agencies')]);state.operators=ops.data;state.agencies=ags.data;
  table('operatorsList',['代码 / 名称','状态 / 白名单','操作'],ops.data.map(o=>[`${o.code} · ${o.name}`,(o.active?'启用':'停用')+' · 白名单'+(Number(o.whitelist_enabled)?'开启':'关闭'),action('编辑',()=>{state.opEdit=o.code;$('opCode').value=o.code;$('opCode').disabled=true;$('opName').value=o.name;$('opContact').value=o.contact;$('opActive').value=o.active;$('opWhitelist').value=o.whitelist_enabled;})]));
  table('agenciesList',['代码 / 名称','票号前缀 / 状态','操作'],ags.data.map(a=>[`${a.code} · ${a.name}`,`${a.issuer_code} · ${a.active?'启用':'停用'}`,action('编辑',()=>{state.agEdit=a.code;$('agCode').value=a.code;$('agCode').disabled=true;$('agName').value=a.name;$('agIssuer').value=a.issuer_code;$('agIssuer').disabled=true;$('agContact').value=a.contact;$('agActive').value=a.active;})]));
  choices('agencyContext',ags.data,a=>a.code,a=>`${a.name} (${a.code}${a.active?'':' · 停用'})`,'默认出票方');choices('grantAgency',ags.data,a=>a.code,a=>`${a.name} (${a.code})`);choices('assignOperator',ops.data.filter(o=>o.active),o=>o.code,o=>`${o.name} (${o.code})`);choices('tripOperator',ops.data.filter(o=>o.active),o=>o.code,o=>`${o.name} (${o.code})`,'全部运营方');choices('operatorScope',ops.data,o=>o.code,o=>`${o.name} (${o.code})`);choices('depotOperator',ops.data.filter(o=>o.active),o=>o.code,o=>`${o.name} (${o.code})`);choices('whitelistOperator',ops.data,o=>o.code,o=>`${o.name} (${o.code})`);choices('whitelistAgency',ags.data,a=>a.code,a=>`${a.name} (${a.code})`);grants();await loadWhitelist();
}
function grants(){const a=state.agencies.find(a=>a.code===$('grantAgency').value);$('grantOperators').replaceChildren();state.operators.forEach(o=>{const l=el('label'),c=el('input');c.type='checkbox';c.value=o.code;c.checked=!!a?.operators.includes(o.code);l.append(c,document.createTextNode(`${o.name} (${o.code}${o.active?'':' · 停用'})`));$('grantOperators').append(l);});}
function resetOp(){state.opEdit=null;$('operatorForm').reset();$('opCode').disabled=false;}
function resetAg(){state.agEdit=null;$('agencyForm').reset();$('agCode').disabled=false;$('agIssuer').disabled=false;}
on('connect',connect);on('disconnect',()=>{state.key='';state.identity=null;state.journey=null;$('apiKey').value='';$('identity').textContent='密钥已清除';$('manageTab').hidden=true;$('management').hidden=true;$('keyResult').hidden=true;$('keyResult').textContent='';$('issue').disabled=true;clearPassengerUI();tab('sales');message('连接身份已清除');});
$('agencyContext').onchange=async()=>{try{const r=await api('/me');state.identity=r.data;$('identity').textContent=`身份：管理员 · 当前出票方 ${state.identity.agency_code} · ${state.identity.agency?.name||'未配置'}`;state.journey=null;$('issue').disabled=true;$('selectedJourney').textContent='出票方已切换，请重新选择车次';$('issuedTicket').replaceChildren();$('ticketResult').replaceChildren();$('keyResult').hidden=true;}catch(e){message(e.message,true);}};
on('search',search);on('swap',()=>{const a=$('origin').value;$('origin').value=$('destination').value;$('destination').value=a;});on('browse',async()=>{state.page=1;await loadDepots();await browse();});
on('tripDepot',async()=>{state.page=1;await browse();},'change');
on('tripDate',async()=>{state.page=1;await loadDepots();await browse();},'change');
on('tripOperator',async()=>{state.page=1;await loadDepots();await browse();},'change');
on('tripIdMode',browse,'change');on('prev',async()=>{state.page=Math.max(1,state.page-1);await browse();});on('next',async()=>{state.page++;await browse();});on('newKey',newKey);
on('issue',async()=>{if(!state.journey)throw Error('先选择车次');const t=state.journey;const body={trip_id:Number(t.id),origin_seq:Number(t.origin_seq),destination_seq:Number(t.destination_seq)};const doc=$('issueDocumentId').value;if(doc){if(!/^[1-9][0-9]*$/.test(doc))throw Error('请输入有效证件库 ID');body.document_id=Number(doc);}else body.passenger=$('passenger').value;const result=await api('/tickets','POST',body,{'Idempotency-Key':$('requestKey').value});renderTicket('issuedTicket',result.data);message('出票成功。重复提交此请求键会返回同一张票。');});
on('lookup',async()=>{const r=await api('/tickets/'+encodeURIComponent($('ticketNumber').value.trim()));renderTicket('ticketResult',r.data);message('客票已加载');});on('refund',async()=>{const n=$('ticketNumber').value.trim();if(!/^\d{13}$/.test(n))throw Error('请输入 13 位票号');if(!confirm(`确定退票 ${n}？`))return;const r=await api('/tickets/'+n+'/refund','POST',{});renderTicket('ticketResult',r.data);message('客票已退票，库存已释放');});
on('operatorForm',async()=>{const b={code:$('opCode').value.trim(),name:$('opName').value.trim(),contact:$('opContact').value.trim(),active:Number($('opActive').value),whitelist_enabled:Number($('opWhitelist').value)};await api('/admin/operators'+(state.opEdit?'/'+state.opEdit:''),state.opEdit?'PUT':'POST',b);resetOp();await management();message('运营方已保存');},'submit');
on('agencyForm',async()=>{const b={code:$('agCode').value.trim(),name:$('agName').value.trim(),issuer_code:$('agIssuer').value,contact:$('agContact').value.trim(),active:Number($('agActive').value)};await api('/admin/agencies'+(state.agEdit?'/'+state.agEdit:''),state.agEdit?'PUT':'POST',b);resetAg();await management();message('出票方已保存');},'submit');
on('resetOperator',resetOp);on('resetAgency',resetAg);$('grantAgency').onchange=grants;
on('saveGrants',async()=>{const operators=[...$('grantOperators').querySelectorAll('input:checked')].map(c=>c.value);await api('/admin/agencies/'+$('grantAgency').value+'/operators','PUT',{operators});await management();message('白名单关联已保存；仅对白名单已开启的运营方生效');});
on('rotateKey',async()=>{const code=$('grantAgency').value;if(!code)throw Error('请选择出票方');if(!confirm(`为 ${code} 重置密钥？旧密钥立即失效。`))return;const r=await api('/admin/agencies/'+code+'/key','POST',{});$('keyResult').textContent=`${code} 的新密钥（仅本次显示，请复制保存）：\n${r.data.api_key}`;$('keyResult').hidden=false;await management();message('出票方密钥已生成');});
on('assign',async()=>{const id=$('assignTripId').value;if(!/^[1-9]\d*$/.test(id))throw Error('请输入有效班次 ID');await api('/admin/trips/'+id+'/operator','PUT',{operator_code:$('assignOperator').value});await browse();message('班次运营方已更新');});









let cleanupPreview=null;
function invalidateCleanup(){cleanupPreview=null;$('clearExecute').disabled=true;$('clearSummary').textContent='';$('clearConfirm').value='';}
$('clearDate').addEventListener('change',invalidateCleanup);
$('clearScope').addEventListener('change',invalidateCleanup);
on('clearPreview',async()=>{
  invalidateCleanup();
  const r=await api('/admin/clear-date','POST',{date:$('clearDate').value,scope:$('clearScope').value,preview:true});
  cleanupPreview=r.data;
  const names={trips:'班次',stops:'停站',imports:'导入批次',tickets:'客票',coupons:'乘车联',requests:'幂等请求',ticket_events:'客票审计',ticket_tlv:'客票 TLV',ticket_tlv_events:'TLV 审计'};
  $('clearSummary').textContent=Object.entries(r.data.counts).map(([k,v])=>names[k]+'：'+v).join('\n')+(r.data.blocked?'\n无法清空：请先清客票，或选择当日全部数据。':'');
  $('clearExecute').disabled=r.data.blocked;
  message('已预览清空范围，尚未删除数据');
});
on('clearExecute',async()=>{
  if(!cleanupPreview||cleanupPreview.date!==$('clearDate').value||cleanupPreview.scope!==$('clearScope').value)throw Error('请先重新预览范围');
  if($('clearConfirm').value!==cleanupPreview.date)throw Error('请输入与运营日相同的日期以确认删除');
  if(!confirm('永久清空 '+cleanupPreview.date+' 的所选数据？此操作无法撤销。'))return;
  const r=await api('/admin/clear-date','POST',{date:cleanupPreview.date,scope:cleanupPreview.scope,preview:false,confirm_date:$('clearConfirm').value});
  state.journey=null;$('issue').disabled=true;$('tripDetail').hidden=true;$('selectedJourney').textContent='数据已清空，请重新选择车次';
  for(const id of ['journeyResults','issuedTicket','ticketResult'])$(id).replaceChildren();
  invalidateCleanup();state.page=1;await loadDepots();await browse();
  message('已清空 '+r.data.date+'：'+JSON.stringify(r.data.counts));
});




function depotOwnerStatus(){
 const selected=$('assignDepot').value;
 if(!selected){$('depotOwnerStatus').textContent='没有可选车厂';return;}
 const [dimension,id]=JSON.parse(selected),depot=state.depots.find(d=>d.dimension===dimension&&d.depot_id===id);
 $('depotOwnerStatus').textContent=depot?.operator_code?'当前归属：'+depot.operator_name+' ('+depot.operator_code+')':'当前未划归运营方';
}
$('assignDepot').onchange=depotOwnerStatus;
on('saveDepotOperator',async()=>{
 if(!$('assignDepot').value)throw Error('请选择车厂');
 const [dimension,depot_id]=JSON.parse($('assignDepot').value),operator_code=$('depotOperator').value;
 if(!operator_code)throw Error('请选择运营方');
 if(!confirm('将该车厂所有线路、班次及后续导入划给 '+operator_code+'？'))return;
 const r=await api('/admin/depots/operator','PUT',{dimension,depot_id,operator_code});
 state.journey=null;$('issue').disabled=true;$('tripDetail').hidden=true;state.page=1;await loadDepots();await browse();
 message('车厂归属已保存，更新 '+r.data.updated_trips+' 个班次');
});
async function loadWhitelist(){
 const code=$('whitelistOperator').value;
 if(!code){$('whitelistList').replaceChildren();return;}
 const w=(await api('/admin/operators/'+encodeURIComponent(code)+'/agencies')).data;
 $('whitelistMode').value=w.enabled?'1':'0';
 table('whitelistList',['出票方','状态','操作'],w.agencies.map(a=>[a.name+' ('+a.code+')',a.active?'启用':'停用',action('移出白名单',async()=>{
  await api('/admin/operators/'+encodeURIComponent(code)+'/agencies/'+encodeURIComponent(a.code),'DELETE');
  await management();message('出票方已移出白名单');
 })]));
}
on('whitelistOperator',loadWhitelist,'change');
on('addWhitelistAgency',async()=>{
 const code=$('whitelistOperator').value,agency_code=$('whitelistAgency').value;
 if(!code||!agency_code)throw Error('请选择运营方和出票方');
 await api('/admin/operators/'+encodeURIComponent(code)+'/agencies','POST',{agency_code});
 await management();message('出票方已加入白名单');
});
on('saveWhitelistMode',async()=>{
 const code=$('whitelistOperator').value,op=state.operators.find(o=>o.code===code);
 if(!op)throw Error('请选择运营方');
 await api('/admin/operators/'+encodeURIComponent(code),'PUT',{name:op.name,contact:op.contact,active:Number(op.active),whitelist_enabled:Number($('whitelistMode').value)});
 await management();message('运营方白名单模式已保存');
});





function resetDocument(){
 state.documentEdit=null;$('documentForm').reset();$('documentFormTitle').textContent='新增旅客证件';
 $('documentVersion').textContent='添加及更新时间由服务器记录。';
}
function editDocument(d){
 state.documentEdit=d;$('documentFormTitle').textContent='编辑证件 #'+d.id;
 for(const [key,id] of [['document_type','documentType'],['document_number','documentNumber'],['issuing_country','documentCountry'],['birth_date','documentBirthDate'],['surname','documentSurname'],['given_name','documentGivenName']])$(id).value=d[key]??'';
 $('documentReason').value='';$('documentVersion').textContent='版本 '+d.version+' · 添加 '+d.created_at+' UTC · 更新 '+d.updated_at+' UTC';
 $('documentForm').scrollIntoView({behavior:'smooth'});
}
async function loadDocuments(){
 const q=new URLSearchParams({page:state.docPage});
 for(const [key,id] of [['document_type','docSearchType'],['document_number','docSearchNumber'],['issuing_country','docSearchCountry'],['name','docSearchName']])if($(id).value)q.set(key,$(id).value);
 const r=await api('/passenger-documents?'+q);state.docTotal=r.total;
 $('docPageInfo').textContent='共 '+r.total+' 份证件 · 第 '+r.page+' 页';$('docPrev').disabled=state.docPage<=1;$('docNext').disabled=state.docPage*30>=r.total;
 table('documentList',['ID / 类型','号码 / 归属地','姓 / 名','出生日期','添加 / 最后更新（UTC）','操作'],r.data.map(d=>{
  const actions=el('div');actions.append(action('编辑',()=>editDocument(d)),action('修改日志',async()=>{
   state.documentHistoryId=d.id;state.documentHistoryPage=1;await loadDocumentHistory();
  }),action('用于出票',()=>{
   $('issueDocumentId').value=d.id;$('passenger').value=d.display_name;$('issueDocumentInfo').textContent='已选择证件 #'+d.id+' · '+d.document_type+' · '+d.document_number+' · '+d.issuing_country+' · '+d.display_name;
   newKey();tab('sales');message('已选择旅客证件，请选定车次区间后出票');
  }));
  return [d.id+' · '+d.document_type,d.document_number+' · '+d.issuing_country,(d.surname||'（无姓）')+' / '+d.given_name,d.birth_date,d.created_at+' / '+d.updated_at,actions];
 }));
}
on('docSearch',async()=>{state.docPage=1;await loadDocuments();});
on('docPrev',async()=>{state.docPage=Math.max(1,state.docPage-1);await loadDocuments();});
on('docNext',async()=>{state.docPage++;await loadDocuments();});
on('newDocument',resetDocument);
on('documentForm',async()=>{
 const body={document_type:$('documentType').value,document_number:$('documentNumber').value,issuing_country:$('documentCountry').value,birth_date:$('documentBirthDate').value,surname:$('documentSurname').value,given_name:$('documentGivenName').value,reason:$('documentReason').value};
 const editing=state.documentEdit;if(editing)body.version=editing.version;
 const r=await api('/passenger-documents'+(editing?'/'+editing.id:''),editing?'PUT':'POST',body);
 resetDocument();state.docPage=1;await loadDocuments();message('证件已保存：#'+r.data.id+' · 版本 '+r.data.version);
},'submit');
function clearPassengerUI(){
 state.documentEdit=null;state.documentHistoryId=null;state.documentHistoryPage=1;state.documentHistoryTotal=0;$('documentHistoryPrev').disabled=true;$('documentHistoryNext').disabled=true;state.docTotal=0;state.operatorTotal=0;state.tlvTotal=0;
 $('salesTab').hidden=false;$('ticketsTab').hidden=false;$('documentsTab').hidden=false;$('operatorTicketsTab').hidden=true;
 $('issueDocumentId').value='';$('issueDocumentInfo').textContent='填写证件库 ID 时，姓名取自证件库并保存出票快照；留空可沿用旅客名称出票。';
 for(const id of ['documentList','operatorTicketList','tlvList'])$(id).replaceChildren();
 for(const id of ['documentHistory','operatorTicketInfo','tlvHistory'])$(id).textContent='';
 $('operatorTicketNumber').value='';$('tlvWire').value='';$('operatorKeyResult').textContent='';$('operatorKeyResult').hidden=true;resetDocument();
}
async function browseOperatorTickets(){
 if(!$('operatorScope').value)throw Error('请选择运营方');
 const q=new URLSearchParams({page:state.operatorPage});if($('operatorTicketDate').value)q.set('date',$('operatorTicketDate').value);
 const r=await api('/operator/tickets?'+q);state.operatorTotal=r.total;
 $('operatorPageInfo').textContent='本运营方共 '+r.total+' 张客票 · 第 '+r.page+' 页';$('operatorPrev').disabled=state.operatorPage<=1;$('operatorNext').disabled=state.operatorPage*30>=r.total;
 table('operatorTicketList',['客票号','旅客','状态','出票方 / 出票时间（UTC）','操作'],r.data.map(t=>[t.ticket_number,t.passenger,t.status,t.agency_code+' · '+t.issued_at,action('查看 / 附加信息',async()=>{
  $('operatorTicketNumber').value=t.ticket_number;state.tlvPage=1;await loadTicketTlv();
 })]));
}
function operatorTicketNumber(){
 const number=$('operatorTicketNumber').value.trim();
 if(!/^[0-9]{13}$/.test(number))throw Error('请输入 13 位客票号');
 return number;
}
async function loadTicketTlv(){
 const number=operatorTicketNumber();
 const ticket=(await api('/operator/tickets/'+number)).data;
 $('operatorTicketInfo').textContent=JSON.stringify(ticket,null,2);
 const r=await api('/operator/tickets/'+number+'/tlv?include_revoked=1&page='+state.tlvPage);state.tlvTotal=r.total;
 $('tlvPageInfo').textContent='运营方 '+$('operatorScope').value+' · '+r.total+' 条（包含已撤销） · 第 '+r.page+' 页';
 $('tlvPrev').disabled=state.tlvPage<=1;$('tlvNext').disabled=state.tlvPage*30>=r.total;
 table('tlvList',['标签 / 说明','编码 / 字节长度','值','状态 / 添加时间（UTC）','操作'],r.data.map(v=>{
  const actions=el('div');actions.append(action('日志',async()=>{
   const log=await api('/operator/tickets/'+number+'/tlv/'+v.id+'/history');$('tlvHistory').textContent=JSON.stringify(log.data,null,2);
  }));
  if(v.active)actions.append(action('撤销',async()=>{
   const reason=prompt('撤销此 TLV 的原因（保留记录与日志）');if(!reason)return;
   await api('/operator/tickets/'+number+'/tlv/'+v.id,'DELETE',{reason});await loadTicketTlv();message('附加信息已撤销，日志保留');
  }));
  const value=el('pre',v.value.length>200?v.value.slice(0,200)+'…':v.value);
  return [v.tag+' · '+v.label,v.encoding+' · '+v.length+' 字节',value,(v.active?'有效':'已撤销')+' · '+v.created_at,actions];
 }));
}
function tlvByteLength(){
 const value=$('tlvValue').value,encoding=$('tlvEncoding').value;
 if(encoding==='utf8')return new TextEncoder().encode(value).length;
 if(encoding==='hex')return value.length%2===0&&/^[0-9a-f]*$/i.test(value)?value.length/2:null;
 try{return atob(value).length;}catch{return null;}
}
function showTlvLength(){const length=tlvByteLength();$('tlvLength').textContent=length===null?'值编码无效':'长度：'+length+' 字节';}
$('tlvValue').addEventListener('input',showTlvLength);$('tlvEncoding').addEventListener('change',showTlvLength);
on('operatorBrowse',async()=>{state.operatorPage=1;await browseOperatorTickets();});
on('operatorPrev',async()=>{state.operatorPage=Math.max(1,state.operatorPage-1);await browseOperatorTickets();});
on('operatorNext',async()=>{state.operatorPage++;await browseOperatorTickets();});
on('operatorLookup',async()=>{state.tlvPage=1;await loadTicketTlv();message('本运营方客票与附加信息已加载');});
on('operatorScope',async()=>{
 state.operatorPage=1;state.tlvPage=1;$('operatorTicketNumber').value='';$('operatorTicketInfo').textContent='';$('tlvList').replaceChildren();$('tlvHistory').textContent='';$('tlvWire').value='';await browseOperatorTickets();
},'change');
on('tlvPrev',async()=>{state.tlvPage=Math.max(1,state.tlvPage-1);await loadTicketTlv();});
on('tlvNext',async()=>{state.tlvPage++;await loadTicketTlv();});
on('tlvForm',async()=>{
 const number=operatorTicketNumber(),body={tag:Number($('tlvTag').value),encoding:$('tlvEncoding').value,value:$('tlvValue').value,label:$('tlvLabel').value},length=tlvByteLength();
 if(length!==null)body.length=length;
 await api('/operator/tickets/'+number+'/tlv','POST',body);await loadTicketTlv();message('TLV 已追加，长度按实际字节计算');
},'submit');
on('tlvExport',async()=>{
 const r=await api('/operator/tickets/'+operatorTicketNumber()+'/tlv-stream');$('tlvWire').value=r.data.wire_base64;
 message('导出 '+r.data.record_count+' 条有效 TLV，共 '+r.data.length+' 字节');
});
on('rotateOperatorKey',async()=>{
 const code=$('whitelistOperator').value;if(!code)throw Error('请选择运营方');
 if(!confirm('为 '+code+' 重置运营方密钥？旧密钥立即失效。'))return;
 const r=await api('/admin/operators/'+encodeURIComponent(code)+'/key','POST',{});
 $('operatorKeyResult').textContent=code+' 的运营方密钥（仅本次显示，请复制保存）：\n'+r.data.api_key;$('operatorKeyResult').hidden=false;
 message('运营方密钥已生成，仅能管理本运营方承运客票的附加信息');
});



async function loadDocumentHistory(){
 if(!state.documentHistoryId)throw Error('请先选择证件的修改日志');
 const h=await api('/passenger-documents/'+state.documentHistoryId+'/history?page='+state.documentHistoryPage);
 state.documentHistoryTotal=h.total;$('documentHistoryPrev').disabled=state.documentHistoryPage<=1;$('documentHistoryNext').disabled=state.documentHistoryPage*30>=h.total;
 $('documentHistory').textContent='证件 #'+state.documentHistoryId+' · 共 '+h.total+' 条 · 第 '+h.page+' 页\n'+JSON.stringify(h.data,null,2);
}
on('documentHistoryPrev',async()=>{state.documentHistoryPage=Math.max(1,state.documentHistoryPage-1);await loadDocumentHistory();});
on('documentHistoryNext',async()=>{state.documentHistoryPage++;await loadDocumentHistory();});

