<?php
require dirname(__DIR__).'/vendor/autoload.php';
$data=(new app\mtr\WorldReader())->read('../world/mtr/minecraft/overworld');$plan=(new app\mtr\Timetable())->build($data);$reasons=[];
foreach($plan['warnings'] as $w){$reason=$w['reason'];$reasons[$reason]=($reasons[$reason]??0)+1;}
$zero=array_filter($data['sidings'],fn($s)=>($s['max_trains']??0)===0&&!($s['unlimited_trains']??false));
$zeroIds=array_map(fn($s)=>(string)$s['id'],$zero);$zeroTrips=array_filter($plan['trips'],fn($t)=>in_array($t['siding_id'],$zeroIds,true));
echo json_encode(['planned_trips'=>count($plan['trips']),'zero_based_one_train_sidings'=>count($zero),'trips_from_raw_zero_sidings'=>count($zeroTrips),'warnings'=>$reasons],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
