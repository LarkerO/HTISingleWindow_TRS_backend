<?php
require dirname(__DIR__).'/vendor/autoload.php';
use app\mtr\WorldReader;
use app\mtr\Timetable;
$data=(new WorldReader())->read(dirname(__DIR__,2).'/world/mtr/minecraft/overworld');
$planner=new Timetable();
foreach($data['depots'] as $d){
 if(!$d['route_ids']||$d['repeat_infinitely'])continue;
 $sidings=[];
 foreach($data['sidings'] as $s){
  if(!WorldReader::area($s,[$d]))continue;
  try{$planner->stops($s,$data['platforms'],$data['stations']);$sidings[]=$s;}catch(RuntimeException $e){}
 }
 if(count($sidings)<2)continue;
 $d['use_real_time']=true;$d['departures']=[0,3600000,7200000,10800000,14400000,18000000];
 $fixture=$data;$fixture['depots']=[$d];$fixture['sidings']=$sidings;
 $plan=$planner->build($fixture);$trips=$plan['trips'];
 if(!$trips||count(array_unique(array_column($trips,'departure_ms')))!==6)throw new RuntimeException('Departure duplicated or lost');
 if(count(array_unique(array_column($trips,'siding_id')))!==count($sidings))throw new RuntimeException('Sidings not rotated');
 $runs=[];foreach($trips as $t){$runs[$t['siding_id']]=($runs[$t['siding_id']]??0)+1;if($t['run_number']!==$runs[$t['siding_id']])throw new RuntimeException('Invalid per-siding run number');}
 $mapped=$planner->build($fixture,null,[(string)$d['id']=>(string)$sidings[0]['id']]);
 if(count(array_unique(array_column($mapped['trips'],'departure_ms')))!==6||count(array_unique(array_column($mapped['trips'],'siding_id')))!==1)throw new RuntimeException('Mapping broken');
 echo "PASS real multi-siding depot: six departures, round robin, per-siding numbering, optional mapping\n";exit;
}
throw new RuntimeException('No multi-siding fixture found');

