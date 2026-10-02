<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';$app=new think\App(dirname(__DIR__));$app->initialize();
use think\facade\Db;
use app\service\PassengerDocuments;
use app\service\TicketTlv;
use app\service\TlvCodec;
use app\service\Ticketing;
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label.PHP_EOL;}
function rejects(callable $fn,int $status):void{try{$fn();}catch(think\exception\HttpException $e){check($e->getStatusCode()===$status,'Expected rejection '.$status);return;}throw new RuntimeException('Expected rejection');}
$before=[];foreach(['passenger_documents','passenger_document_events','ticket_tlv','ticket_tlv_events','tickets','trips'] as $table)$before[$table]=Db::table($table)->count();
Db::startTrans();
try{
 $docs=new PassengerDocuments();$admin=['role'=>'admin','agency_code'=>'LOCAL'];
 $base=['document_type'=>'PASSPORT','document_number'=>'T'.bin2hex(random_bytes(8)),'issuing_country'=>'chn','birth_date'=>'1990-02-03','surname'=>'','given_name'=>'测试旅客','reason'=>'Initial registration'];
 $doc=$docs->save($base,$admin);
 check($doc['issuing_country']==='CHN'&&$doc['surname']===null&&$doc['version']===1&&$doc['created_at']===$doc['updated_at'],'Global document fields, optional surname and server timestamps');
 rejects(fn()=>$docs->save($base,$admin),409);
 rejects(fn()=>$docs->save(array_replace($base,['issuing_country'=>'CN']),$admin),422);
 rejects(fn()=>$docs->save(array_replace($base,['birth_date'=>'1990-02-30']),$admin),422);
 rejects(fn()=>$docs->save(array_replace($base,['created_at'=>'2000-01-01']),$admin),422);
 $agency=['role'=>'agency','agency_code'=>'LOCAL'];$update=array_replace($base,['given_name'=>'更新旅客','surname'=>'李','version'=>1,'reason'=>'Correct name']);
 $changed=$docs->save($update,$agency,$doc['id']);
 check($changed['version']===2&&$changed['display_name']==='李 更新旅客'&&$changed['created_at']===$doc['created_at'],'Document update increments revision and preserves creation time');
 $history=$docs->history($doc['id']);
 check($history['total']===2&&$history['data'][0]['before']['given_name']==='测试旅客'&&$history['data'][0]['after']['given_name']==='更新旅客'&&$history['data'][0]['actor_code']==='LOCAL','Modification log records before, after, reason and actor');
 rejects(fn()=>$docs->save($update,$agency,$doc['id']),409);
 $update['version']=2;$same=$docs->save($update,$agency,$doc['id']);
 check($same['version']===2&&$docs->history($doc['id'])['total']===2,'Unchanged update leaves version and history intact');
 $source=Db::table('trips')->where('active',1)->order('id')->find();
 $stops=Db::table('stops')->where('trip_id',$source['id'])->order('seq')->limit(2)->select()->toArray();
 $trip=$source;unset($trip['id']);$trip['dimension']='passenger-test';$trip['service_date']='2099-05-20';$trip['operator_code']='LOCAL';$trip['capacity']=10;$tripId=(int)Db::table('trips')->insertGetId($trip);
 foreach($stops as $seq=>$stop){$stop['trip_id']=$tripId;$stop['seq']=$seq;$stop['arrival_at']='2099-05-20 10:0'.$seq.':00';$stop['departure_at']='2099-05-20 10:0'.$seq.':30';Db::table('stops')->insert($stop);}
 $body=['trip_id'=>$tripId,'origin_seq'=>0,'destination_seq'=>1,'document_id'=>$doc['id']];$key='doc-test-'.bin2hex(random_bytes(8));
 $tickets=new Ticketing();$ticket=$tickets->issue($body,$key,'LOCAL');
 check($ticket['passenger']==='李 更新旅客'&&$ticket['passenger_document_id']==$doc['id']&&$ticket['passenger_document_snapshot']['version']===2,'Issuance uses registered document and frozen snapshot');
 $update['given_name']='再改旅客';$docs->save($update,$admin,$doc['id']);
 $retry=$tickets->issue($body,$key,'LOCAL');
 check($retry['ticket_number']===$ticket['ticket_number']&&$retry['passenger_document_snapshot']['given_name']==='更新旅客','Document edits preserve ticket snapshot and issue idempotency');
 $tlv=new TicketTlv();$operator=['role'=>'operator','operator_code'=>'LOCAL'];$number=$ticket['ticket_number'];
 $entry=$tlv->append($number,'LOCAL',['tag'=>10,'encoding'=>'utf8','value'=>'中文','length'=>6],$operator);
 check($entry['length']===6&&$entry['value']==='中文','TLV counts UTF-8 bytes rather than characters');
 $wire=base64_decode($entry['wire_base64']);$header=unpack('ntag/Nlength',substr($wire,0,6));
 check($header===['tag'=>10,'length'=>6]&&substr($wire,6)==='中文','Binary TLV header is big-endian uint16 + uint32');
 $binary=$tlv->append($number,'LOCAL',['tag'=>10,'encoding'=>'hex','value'=>'00FF80'],$operator);
 check($binary['length']===3&&$tlv->listing($number,'LOCAL',$operator)['total']===2,'Binary bytes and repeated tags append independently');
 rejects(fn()=>$tlv->append($number,'LOCAL',['tag'=>1,'value'=>'中文','length'=>2],$operator),422);
 rejects(fn()=>$tlv->append($number,'LOCAL',['tag'=>65536,'value'=>'bad'],$operator),422);
 rejects(fn()=>TlvCodec::input(['tag'=>1,'encoding'=>'hex','value'=>'0']),422);
 rejects(fn()=>TlvCodec::input(['tag'=>1,'encoding'=>'base64','value'=>'!!!!']),422);
 rejects(fn()=>$tlv->append($number,'LOCAL',['tag'=>1,'value'=>'agency'],$agency),403);
 $other='P'.strtoupper(bin2hex(random_bytes(4)));(new app\service\Organizations())->save('operators',['code'=>$other,'name'=>'Other operator']);
 rejects(fn()=>$tlv->get($number,$other,['role'=>'operator','operator_code'=>$other]),404);
 rejects(fn()=>$tlv->get($number,'LOCAL',['role'=>'operator','operator_code'=>$other]),403);
 $stream=$tlv->stream($number,'LOCAL',$operator);
 check(base64_decode($stream['wire_base64'])===$wire.base64_decode($binary['wire_base64'])&&$stream['record_count']===2,'Active TLVs export in append order without expiry');
 $tlv->revoke($number,'LOCAL',$entry['id'],'Incorrect note',$operator);$tlv->revoke($number,'LOCAL',$entry['id'],'Already revoked',$operator);
 check($tlv->listing($number,'LOCAL',$operator)['total']===1&&$tlv->listing($number,'LOCAL',$operator,1,true)['total']===2,'Revoked entries remain auditable and disappear from active stream');
 check(Db::table('ticket_tlv_events')->where('tlv_id',$entry['id'])->where('action','REVOKE')->count()===1,'TLV revocation is idempotent');
 $keyResult=(new app\service\Organizations())->rotateOperatorKey('LOCAL');
 check(Db::table('operators')->where('code','LOCAL')->value('api_key_hash')===hash('sha256',$keyResult['api_key']),'Operator key stored only as a hash');
 (new app\service\DateCleanup())->run('2099-05-20','tickets',false);
 check(!Db::table('ticket_tlv')->where('ticket_id',$ticket['id'])->count()&&Db::table('passenger_documents')->where('id',$doc['id'])->count()===1&&$docs->history($doc['id'])['total']===3,'Date ticket cleanup removes TLVs but preserves global documents and history');
}finally{Db::rollback();}
foreach($before as $table=>$count)check(Db::table($table)->count()===$count,'Rollback restored '.$table);
