<?php
require dirname(__DIR__).'/vendor/autoload.php';
use app\mtr\WorldReader;
use app\mtr\Timetable;
$data=(new WorldReader())->read('../world/mtr/minecraft/overworld');
$planner=new Timetable();$plan=$planner->build($data);$sample=null;
foreach($plan['trips'] as $t){$s=$data['sidings'][$t['siding_id']];if(($s['max_trains']??0)===0&&!($s['unlimited_trains']??false)){$sample=$t;break;}}
if(!$sample)throw new RuntimeException('No raw-zero siding admitted');
$depot=$data['depots'][$sample['depot_id']];$depot['use_real_time']=true;$depot['repeat_infinitely']=false;$depot['departures']=[0,3600000];
$siding=$data['sidings'][$sample['siding_id']];$siding['max_trains']=0;$siding['unlimited_trains']=false;
$fixture=$data;$fixture['depots']=[$depot['id']=>$depot];$fixture['sidings']=[$siding['id']=>$siding];
$zero=$planner->build($fixture);
if(count(array_unique(array_column($zero['trips'],'departure_ms')))!==2||$zero['warnings'])throw new RuntimeException('Raw zero incorrectly excludes a scheduled single train');
echo 'PASS max_trains=0, unlimited=false: single train can have multiple scheduled departures'.PHP_EOL;
$fixture['sidings'][$siding['id']]['max_trains']=1;$one=$planner->build($fixture);
if($zero['trips']!==$one['trips'])throw new RuntimeException('Zero-based fleet limit changes planned train schedule');
echo 'PASS Fleet limit is not an automatic-departure switch'.PHP_EOL;
$fixture['sidings'][$siding['id']]['unlimited_trains']=true;$unlimited=$planner->build($fixture);
if($zero['trips']!==$unlimited['trips'])throw new RuntimeException('Unlimited fleet changes planned departures');
echo 'PASS Fleet pool settings do not change the depot departure table'.PHP_EOL;

