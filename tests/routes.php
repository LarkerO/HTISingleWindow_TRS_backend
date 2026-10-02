<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use app\mtr\RoutePlan;
use app\mtr\Timetable;
use app\mtr\WorldReader;
function verify(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
function mismatch(callable $fn): void {try{$fn();}catch(RuntimeException $e){return;}throw new RuntimeException('Expected stale path rejection');}
$data=['platforms'=>['1'=>[], '2'=>[], '3'=>[]],'routes'=>[
 '10'=>['name'=>'Outbound','platform_ids'=>[1,1,2,3]],
 '11'=>['name'=>'Inbound','platform_ids'=>[3,2,1]]]];
$layout=RoutePlan::layout(['route_ids'=>[10,11]],$data);
verify($layout['platforms']===['1','2','3','2','1'],'Java concatenation keeps return calls and merges only adjacent platform IDs');
$s=['id'=>99,'path'=>[]];$stops=[];
foreach($layout['platforms'] as $i=>$pid){
 $s['path'][]=['stop_index'=>$i+1,'saved_rail_base_id'=>$pid];
 $stops[]=['station_id'=>$pid,'platform_id'=>$pid,'stop_index'=>$i+1,'arrival_offset_ms'=>$i*10000,'departure_offset_ms'=>$i*10000+1000];
}
$legs=RoutePlan::split($s,$stops,$layout);
verify(count($legs)===2 && array_column($legs[0]['stops'],'platform_id')===['1','2','3'] && array_column($legs[1]['stops'],'platform_id')===['3','2','1'],'Outbound and inbound are separate trips, not head-tail-head');
verify($legs[1]['stops'][0]['arrival_offset_ms']===20000,'Return departure retains full-path time offset');
$duplicate=$stops;array_splice($duplicate,2,0,[$stops[1]]);
verify(count(RoutePlan::split($s,$duplicate,$layout)[0]['stops'])===3,'Repeated traversal at same stop index is one call');
$bad=$s;$bad['path'][1]['saved_rail_base_id']='3';mismatch(fn()=>RoutePlan::split($bad,$stops,$layout));
$bad=$s;array_pop($bad['path']);mismatch(fn()=>RoutePlan::split($bad,$stops,$layout));
verify(true,'Wrong order and incomplete saved paths rejected');
$loop=RoutePlan::layout(['route_ids'=>[10]],['platforms'=>$data['platforms'],'routes'=>['10'=>['name'=>'Loop','platform_ids'=>[1,2,1]]]]);
$loopPath=['id'=>99,'path'=>[]];$loopStops=[];
foreach($loop['platforms'] as $i=>$pid){$loopPath['path'][]=['stop_index'=>$i+1,'saved_rail_base_id'=>$pid];$loopStops[]=['station_id'=>$pid,'platform_id'=>$pid,'stop_index'=>$i+1,'arrival_offset_ms'=>$i*1000,'departure_offset_ms'=>$i*1000];}
verify(array_column(RoutePlan::split($loopPath,$loopStops,$loop)[0]['stops'],'platform_id')===['1','2','1'],'Explicit loop in a single route is preserved');
$pass=$stops;array_splice($pass,1,1);
verify(array_column(RoutePlan::split($s,$pass,$layout)[0]['stops'],'platform_id')===['1','3'],'Zero-dwell platform remains pass-through, not a passenger stop');
$world=(new WorldReader())->read(dirname(__DIR__,2).'/world/mtr/minecraft/overworld');
$plan=(new Timetable())->build($world);$keys=[];$runs=[];
foreach($plan['trips'] as $trip) {
 if(count($trip['route_ids'])!==1)throw new RuntimeException('Trip mixes routes');
 $depot=$world['depots'][$trip['depot_id']];$layout=RoutePlan::layout($depot,$world);$leg=null;
 foreach($layout['legs'] as $l)if($l['route_id']===$trip['route_ids'][0] && in_array($trip['stops'][0]['stop_index'],$l['indices'],true)){$leg=$l;break;}
 $previous=0;$time=-1;
 foreach($trip['stops'] as $stop){
  $index=$stop['stop_index'];
  if(!in_array($index,$leg['indices'],true)||$index<=$previous||$layout['platforms'][$index-1]!==$stop['platform_id']||$stop['arrival_offset_ms']<$time)throw new RuntimeException('Real route stop order/time mismatch');
  $previous=$index;$time=$stop['departure_offset_ms'];
 }
 if(isset($keys[$trip['key']]))throw new RuntimeException('Duplicate passenger trip');
 $keys[$trip['key']]=true;$group=$trip['depot_id'].':'.$trip['siding_id'];$runs[$group]=($runs[$group]??0)+1;
 if($trip['run_number']!==$runs[$group])throw new RuntimeException('Run number collision');
}
verify(count($plan['trips'])>0,'All '.count($plan['trips']).' real directional trips match configured stops, times and unique identities');

