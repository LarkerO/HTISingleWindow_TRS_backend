<?php
require dirname(__DIR__).'/vendor/autoload.php';
$app=new think\App(dirname(__DIR__)); $app->initialize();
$port=18764; $pipes=[];
$proc=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',dirname(__DIR__).'/public',dirname(__DIR__).'/public/router.php'],[0=>['pipe','r'],1=>['file',dirname(__DIR__).'/runtime/http-test.log','a'],2=>['file',dirname(__DIR__).'/runtime/http-test.log','a']],$pipes,dirname(__DIR__));
if(!is_resource($proc)) throw new RuntimeException('Cannot start test HTTP server');
function request(string $path,?string $key=null): array {
    global $port;
    $c=curl_init('http://127.0.0.1:'.$port.$path); curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>$key?['X-API-Key: '.$key]:[]]);
    $body=curl_exec($c); $status=curl_getinfo($c,CURLINFO_HTTP_CODE); curl_close($c); return [$status,json_decode($body?:'',true)];
}
try {
    for($i=0;$i<30;$i++){usleep(100000); [$status,$data]=request('/health');if($status===200)break;}
    if($status!==200||$data['status']!=='ok') throw new RuntimeException('Health endpoint failed'); echo 'PASS HTTP health'.PHP_EOL;
    [$status]=request('/api/v1/stations'); if($status!==401) throw new RuntimeException('Auth failed: '.$status); echo 'PASS HTTP authentication'.PHP_EOL;
    $key=config('ticketing.api_key'); [$status,$data]=request('/api/v1/stations?q='.urlencode('广阳'),$key); if($status!==200||!count($data['data']??[])) throw new RuntimeException('Stations API failed: '.$status); echo 'PASS HTTP station search'.PHP_EOL;
    [$status]=request('/api/v1/journeys?date=bad',$key); if($status!==422) throw new RuntimeException('Validation failed: '.$status); echo 'PASS HTTP error format'.PHP_EOL;
    [$status]=request('/Api/stations'); if($status!==404) throw new RuntimeException('Controller bypass: '.$status); echo 'PASS Direct controller access rejected'.PHP_EOL;
} finally { proc_terminate($proc);foreach($pipes as $p)fclose($p);proc_close($proc); }
