<?php
require dirname(__DIR__).'/vendor/autoload.php';
$app=new think\App(dirname(__DIR__));$app->initialize();
use think\facade\Db;
$report=json_decode(file_get_contents(dirname(__DIR__).'/runtime/import-report.json'),true);
$reasons=[];foreach($report['warnings'] as $w){$k=preg_replace('/; eligible count=\d+/','',$w['reason']);$reasons[$k]=($reasons[$k]??0)+1;}
echo json_encode(['active_trips'=>Db::table('trips')->where('active',1)->count(),'active_stops'=>Db::table('stops')->alias('s')->join('trips t','t.id=s.trip_id')->where('t.active',1)->count(),'stations'=>Db::table('stations')->count(),'tickets'=>Db::table('tickets')->count(),'warning_count'=>count($report['warnings']),'warning_reasons'=>$reasons],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
