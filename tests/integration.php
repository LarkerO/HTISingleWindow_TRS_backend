<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=new think\App(dirname(__DIR__)); $app->initialize();
use think\facade\Db;
use app\service\Ticketing;
function check(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); echo 'PASS '.$message.PHP_EOL; }
function rejects(callable $fn,int $status,string $message): void { try{$fn();}catch(think\exception\HttpException $e){check($e->getStatusCode()===$status,$message);return;} throw new RuntimeException('Expected rejection: '.$message); }
check((new app\mtr\MessagePack(hex2bin('81a26964d3ffffffffffffffff')))->decode()['id']===-1,'Signed 64-bit MTR IDs preserved');
try{(new app\mtr\MessagePack(hex2bin('81a26964')))->decode();throw new RuntimeException('Expected truncation');}catch(RuntimeException $e){check($e->getMessage()==='Truncated MessagePack','Malformed MessagePack rejected');}
$world=(new app\mtr\WorldReader())->read(dirname(__DIR__,2).'/world/mtr/minecraft/overworld.zip');
check(count($world['stations'])===235 && count($world['platforms'])===637,'Provided world decoded');
Db::startTrans();
try {
    $trip=Db::table('trips')->where('active',1)->find(); check((bool)$trip,'Real timetable persisted');
    $stops=Db::table('stops')->where('trip_id',$trip['id'])->order('seq')->select()->toArray();
    check(count($stops)>=2,'Passenger stops persisted');
    for($i=1;$i<count($stops);$i++) check($stops[$i]['arrival_at']>=$stops[$i-1]['departure_at'],'Stop times monotonic '.$i);
    Db::table('trips')->where('id',$trip['id'])->update(['capacity'=>1]); $trip['capacity']=1;
    $service=new Ticketing(); $body=['trip_id'=>(int)$trip['id'],'origin_seq'=>0,'destination_seq'=>1,'passenger'=>'Integration Test'];
    $key='test-'.bin2hex(random_bytes(8)); $agency='TEST';
    Db::table('agencies')->insert(['code'=>'TEST','name'=>'Test agency','issuer_code'=>'998']);
    Db::table('agency_operators')->insert(['agency_code'=>'TEST','operator_code'=>$trip['operator_code']]);
    $ticket=$service->issue($body,$key,$agency);
    check(strlen($ticket['ticket_number'])===13&&$ticket['status']==='OPEN','Electronic ticket and OPEN coupon issued');
    check($service->issue($body,$key,$agency)['ticket_number']===$ticket['ticket_number'],'Issue idempotency');
    rejects(fn()=>$service->issue(array_merge($body,['passenger'=>'Other']),$key,$agency),409,'Conflicting idempotency rejected');
    rejects(fn()=>$service->issue($body,$key.'-2',$agency),409,'Overlapping segment cannot oversell');
    check($service->available($trip,0,1)===0,'Occupied segment inventory');
    if(count($stops)>2) { check($service->available($trip,1,2)===1,'Adjacent segment inventory reusable'); $t2=$service->issue(array_merge($body,['origin_seq'=>1,'destination_seq'=>2]),$key.'-3',$agency); }
    rejects(fn()=>$service->get($ticket['ticket_number'],'OTHER'),404,'Agency ticket isolation');
    $refunded=$service->refund($ticket['ticket_number'],$agency);
    check($refunded['status']==='RFND'&&$refunded['coupons'][0]['status']==='RFND','Refund changes ticket and coupon');
    check($service->available($trip,0,1)===1,'Refund restores inventory');
    $service->refund($ticket['ticket_number'],$agency);
    check(Db::table('ticket_events')->where('ticket_id',$ticket['id'])->where('action','REFUND')->count()===1,'Refund idempotency');
    rejects(fn()=>$service->issue(array_merge($body,['destination_seq'=>0]),$key.'-bad',$agency),422,'Invalid station interval rejected');
    $rows=$service->search($stops[0]['station_id'],$stops[1]['station_id'],substr($stops[0]['departure_at'],0,10));
    check(count($rows)>0,'Station interval journey query');
    $data=$world; $plan=(new app\mtr\Timetable())->build($data);
    try { (new app\service\Importer())->import($data,$plan,'minecraft/overworld',$trip['service_date'],str_repeat('0',64),'LOCAL',100,100); throw new RuntimeException('Expected import rejection'); }
    catch(RuntimeException $e){check($e->getMessage()==='Cannot replace a service date with issued tickets','Sold timetable cannot be replaced');}
} finally { Db::rollback(); }
echo 'All tests passed; database test writes rolled back.'.PHP_EOL;

