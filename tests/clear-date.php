<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=new think\App(dirname(__DIR__));$app->initialize();
use think\facade\Db;
use app\service\DateCleanup;
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
function rejects(callable $fn,int $status):void{try{$fn();}catch(think\exception\HttpException $e){check($e->getStatusCode()===$status,'Expected rejection '.$status);return;}throw new RuntimeException('Expected rejection');}
$service=new DateCleanup();
rejects(fn()=>$service->run('2097-02-30','all'),422);
rejects(fn()=>$service->run('2097-02-01','unknown'),422);
$tables=['imports','trips','stops','tickets','coupons','requests','ticket_events','stations','operators','agencies','mtr_depots'];
$before=[];foreach($tables as $t)$before[$t]=Db::table($t)->count();
Db::startTrans();
try {
 $date='2097-05-11';$other='2097-05-12';
 check(!Db::table('imports')->whereIn('service_date',[$date,$other])->count()&&!Db::table('trips')->whereIn('service_date',[$date,$other])->count(),'Test dates are unused');
 $station=Db::table('stations')->where('dimension','minecraft/overworld')->find()['id'];$tripIds=[];$ticketIds=[];
 foreach([$date,$date,$other] as $n=>$day){
  $import=Db::table('imports')->insertGetId(['dimension'=>'cleanup-test','service_date'=>$day,'source_hash'=>str_repeat('0',64),'report'=>'{}']);
  $trip=Db::table('trips')->insertGetId(['import_id'=>$import,'dimension'=>'cleanup-test','service_date'=>$day,'trip_key'=>hash('sha256','cleanup-'.$n),'operator_code'=>'LOCAL','name'=>'Cleanup test','depot_id'=>'1','siding_id'=>'2','run_number'=>$n+1,'route_ids'=>'[]','capacity'=>1,'fare_per_segment'=>100,'active'=>$n===1?0:1]);
  $tripIds[]=$trip;
  foreach([0,1] as $seq)Db::table('stops')->insert(['trip_id'=>$trip,'seq'=>$seq,'station_id'=>$station,'platform_id'=>'1','arrival_at'=>$day.' 23:59:00','departure_at'=>$seq?(new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d').' 00:01:00':$day.' 23:59:00']);
  $ticket=Db::table('tickets')->insertGetId(['ticket_number'=>'997'.str_pad((string)random_int(0,9999999999),10,'0',STR_PAD_LEFT),'pnr'=>strtoupper(substr(bin2hex(random_bytes(6)),0,6)),'issuer_code'=>'997','agency_code'=>'LOCAL','passenger'=>'Cleanup test','amount_minor'=>100,'currency'=>'CNY','status'=>$n===1?'RFND':'OPEN','issued_at'=>'2096-01-01 00:00:00']);
  $ticketIds[]=$ticket;
  Db::table('coupons')->insert(['ticket_id'=>$ticket,'trip_id'=>$trip,'origin_seq'=>0,'destination_seq'=>1,'status'=>$n===1?'RFND':'OPEN']);
  Db::table('requests')->insert(['ticket_id'=>$ticket,'agency_code'=>'LOCAL','request_key'=>'cleanup-'.$ticket,'body_hash'=>str_repeat('0',64)]);
  Db::table('ticket_events')->insert(['ticket_id'=>$ticket,'agency_code'=>'LOCAL','action'=>'ISSUE']);
 }
 $preview=$service->run($date,'all');
 check($preview['counts']['tickets']===2 && $preview['counts']['trips']===2 && $preview['counts']['stops']===4,'Preview uses service date across midnight and includes inactive revisions/refunded tickets');
 check(Db::table('tickets')->whereIn('id',$ticketIds)->count()===3,'Preview leaves all data intact');
 check($service->run($date,'timetable')['blocked'],'Timetable preview reports issued-ticket block');
 rejects(fn()=>$service->run($date,'timetable',false),409);
 Db::startTrans();
 $service->run($date,'all',false);
 check(!Db::table('trips')->whereIn('id',array_slice($tripIds,0,2))->count()&&!Db::table('tickets')->whereIn('id',array_slice($ticketIds,0,2))->count(),'All removes plans and associated tickets');
 check(Db::table('trips')->where('id',$tripIds[2])->count()===1 && Db::table('tickets')->where('id',$ticketIds[2])->count()===1,'Other service date survives');
 Db::rollback();
 $service->run($date,'tickets',false);
 check(Db::table('trips')->whereIn('id',$tripIds)->count()===3 && !Db::table('coupons')->whereIn('trip_id',array_slice($tripIds,0,2))->count(),'Tickets-only releases inventory and retains plan');
 $service->run($date,'timetable',false);
 check(!Db::table('imports')->where('service_date',$date)->count() && !Db::table('stops')->whereIn('trip_id',array_slice($tripIds,0,2))->count(),'Timetable-only removes stops, trips and import revisions');
 check($service->run($date,'all',false)['counts']['trips']===0,'Repeated cleanup is harmless');
 foreach(['stations','operators','agencies','mtr_depots'] as $t)check(Db::table($t)->count()===$before[$t],'Shared '.$t.' preserved');
} finally {Db::rollback();}
foreach($tables as $t)check(Db::table($t)->count()===$before[$t],'Rollback restored '.$t);

