<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';$app=new think\App(dirname(__DIR__));$app->initialize();
use think\facade\Db;
use app\service\Importer;
$data=(new app\mtr\WorldReader())->read('../world/mtr/minecraft/overworld');
$plan=(new app\mtr\Timetable())->build($data);$first=$plan['trips'][0];$sample=array_values(array_filter($plan['trips'],fn($t)=>$t['physical_key']===$first['physical_key']));
$date='2097-05-13';
if(Db::table('imports')->where('service_date',$date)->count())throw new RuntimeException('Test date in use');
$before=Db::table('trips')->count();
Db::startTrans();
try {
 $importer=new Importer();$legacyPlan=$plan;
 $legacy=$first;$legacy['key']=$first['physical_key'];$legacy['name']='Legacy whole cycle';
 $legacyPlan['trips']=[$legacy];
 $importer->import($data,$legacyPlan,'minecraft/overworld',$date,str_repeat('0',64),'LOCAL',77,123);
 $legacyId=Db::table('trips')->where('service_date',$date)->value('id');
 $plan['trips']=$sample;
 $importer->import($data,$plan,'minecraft/overworld',$date,str_repeat('1',64),'LOCAL',999,999,true);
 $rows=Db::table('trips')->where('service_date',$date)->where('active',1)->select()->toArray();
 if(count($rows)!==count($sample)||Db::table('trips')->where('id',$legacyId)->value('active')!=0)throw new RuntimeException('Legacy cycle not replaced by directional trips');
 foreach($rows as $r)if($r['capacity']!=77||$r['fare_per_segment']!=123||count(json_decode($r['route_ids'],true))!==1)throw new RuntimeException('Split loses legacy settings or route identity');
 echo 'PASS Legacy cycle retires; directional trips inherit operator, capacity and fare'.PHP_EOL;
 $byKey=array_column($rows,'id','trip_key');
 $importer->import($data,$plan,'minecraft/overworld',$date,str_repeat('2',64),'LOCAL',888,888,true);
 foreach(Db::table('trips')->where('service_date',$date)->where('active',1)->select() as $r)if($byKey[$r['trip_key']]!=$r['id']||$r['capacity']!=77)throw new RuntimeException('Repeat merge changes ID or settings');
 echo 'PASS Directional reimport retains matching IDs and settings'.PHP_EOL;
 $new=$first;$new['key']=hash('sha256','new-merge-test');$new['run_number']=999999;
 $plan['trips']=[$first,$new];
 $importer->import($data,$plan,'minecraft/overworld',$date,str_repeat('3',64),'LOCAL',999,999,true);
 if(Db::table('trips')->where('service_date',$date)->where('active',1)->count()!==2||!Db::table('trips')->where('service_date',$date)->where('trip_key',$new['key'])->where('active',1)->find())throw new RuntimeException('New trip insertion or retirement failed');
 echo 'PASS Merge inserts new trips and retires absent trips'.PHP_EOL;
} finally {Db::rollback();}
if(Db::table('trips')->count()!==$before)throw new RuntimeException('Rollback failed');
echo 'PASS Merge/import test database changes rolled back'.PHP_EOL;
