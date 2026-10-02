<?php
require dirname(__DIR__).'/vendor/autoload.php';$app=new think\App(dirname(__DIR__));$app->initialize();
use think\facade\Db;
$data=(new app\mtr\WorldReader())->read('../world/mtr/minecraft/overworld');$plan=(new app\mtr\Timetable())->build($data);$keys=array_column($plan['trips'],null,'key');$old=Db::table('trips')->where('active',1)->where('dimension','minecraft/overworld')->where('service_date','2026-10-04')->select()->toArray();$missing=0;$matches=0;foreach($old as $t){if(isset($keys[$t['trip_key']]))$matches++;else $missing++;}
echo json_encode(['catalog_depots'=>Db::table('mtr_depots')->count(),'current_stations'=>count($data['stations']),'current_planned_trips'=>count($keys),'existing_trips'=>count($old),'matching_keys'=>$matches,'old_keys_not_in_new_source'=>$missing,'ticket_count'=>Db::table('tickets')->count(),'operator_codes'=>Db::table('trips')->where('active',1)->distinct(true)->column('operator_code')],JSON_PRETTY_PRINT).PHP_EOL;
