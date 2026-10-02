<?php
require dirname(__DIR__).'/vendor/autoload.php';
$app=new think\App(dirname(__DIR__));$app->initialize();
use think\facade\Db;
$port=18766;$pipes=[];$proc=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',dirname(__DIR__).'/public',dirname(__DIR__).'/public/router.php'],[0=>['pipe','r'],1=>['file',dirname(__DIR__).'/runtime/depot-test.log','a'],2=>['file',dirname(__DIR__).'/runtime/depot-test.log','a']],$pipes,dirname(__DIR__));
function getApi(string $path): array { global $port;$c=curl_init('http://127.0.0.1:'.$port.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>['X-API-Key: '.config('ticketing.api_key')]]);$raw=curl_exec($c);$code=curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);return [$code,json_decode($raw?:'',true)]; }
function verify(bool $condition,string $text): void { if(!$condition)throw new RuntimeException($text);echo 'PASS '.$text.PHP_EOL; }
try{
    for($i=0;$i<30;$i++){usleep(100000);if(getApi('/health')[0]===200)break;}
    $trip=Db::table('trips')->where('active',1)->find();$date=$trip['service_date'];
    [$code,$body]=getApi('/api/v1/depots?date='.$date);verify($code===200&&count($body['data'])>0,'MTR depot choices');
    $current=(new app\mtr\WorldReader())->read('../world/mtr/minecraft/overworld');
    verify(count($body['data'])===count($current['depots']),'All source depots are listed, including zero-trip depots');
    $listed=array_column($body['data'],'depot_id'); foreach($current['depots'] as $d)if(!in_array((string)$d['id'],$listed,true))throw new RuntimeException('Missing source depot');
    $blocked=array_values(array_filter($body['data'],fn($d)=>$d['trip_count']===0));
    verify(count($blocked)>0&&isset($blocked[0]['planning_reason'],$blocked[0]['sidings']),'Zero-trip depot diagnostic and sidings exposed');
    $foundZero=false; foreach($body['data'] as $d)foreach($d['sidings'] as $siding)if((int)$siding['max_trains']===0&&!$siding['unlimited_trains']){if($siding['vehicle_limit']!==1)throw new RuntimeException('Raw zero should display one train');$foundZero=true;}
    verify($foundZero,'Vehicle limit API translates stored zero to one train');
    $depot=array_values(array_filter($body['data'],fn($d)=>$d['trip_count']>30))[0];verify(is_string($depot['depot_id']),'MTR 64-bit depot IDs remain strings');
    $query=http_build_query(['date'=>$date,'dimension'=>$depot['dimension'],'depot_id'=>$depot['depot_id']]);
    [$code,$body]=getApi('/api/v1/trips?'.$query);verify($code===200&&$body['total']==$depot['trip_count'],'Depot filter matches exact imported count');
    foreach($body['data'] as $t)verify($t['depot_id']===$depot['depot_id']&&$t['dimension']===$depot['dimension']&&$t['trip_code']===$t['depot_id'].'+'.$t['siding_id'].'+'.$t['run_number'],'Depot filter and composite identity '.$t['id']);
    $first=$body['data'][0];verify($first['run_number']===1,'First daily departure starts at one');
    if($body['total']>30){[$code,$next]=getApi('/api/v1/trips?'.$query.'&page=2');verify($code===200&&$next['data'][0]['run_number']===31,'Run number continues across pagination');}
    [$code,$detail]=getApi('/api/v1/trips/'.$first['id']);verify($detail['data']['trip_code']===$first['trip_code'],'Trip details share display identity');
    $stops=$detail['data']['stops'];$to=null;foreach(array_reverse($stops) as $s)if($s['station_id']!==$stops[0]['station_id']){$to=$s;break;}
    if($to){[$code,$journeys]=getApi('/api/v1/journeys?'.http_build_query(['origin'=>$stops[0]['station_id'],'destination'=>$to['station_id'],'date'=>substr($stops[0]['departure_at'],0,10)]));verify($code===200&&isset($journeys['data'][0]['trip_code']),'Journey search includes composite identity');}
    [$code]=getApi('/api/v1/trips?depot_id=not-an-id');verify($code===422,'Invalid depot filter rejected');
    [$code,$empty]=getApi('/api/v1/trips?'.$query.'&operator=DOESNOTEXIST');verify($code===200&&$empty['total']===0,'Depot and operator filters combine');
    $data=(new app\mtr\WorldReader())->read(dirname(__DIR__,2).'/world/mtr/minecraft/overworld.zip');$plan=(new app\mtr\Timetable())->build($data);$last=[];
    foreach($plan['trips'] as $t){$key=$t['depot_id'].':'.$t['siding_id'];$expected=($last[$key]??0)+1;if($t['run_number']!==$expected)throw new RuntimeException('New imports run number incorrect');$last[$key]=$expected;}
    verify(count($plan['trips'])>0,'New import plans contain sequential daily run numbers');
}finally{proc_terminate($proc);foreach($pipes as $p)fclose($p);proc_close($proc);}


