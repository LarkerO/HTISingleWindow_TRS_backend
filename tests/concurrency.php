<?php
require dirname(__DIR__).'/vendor/autoload.php';
$app=new think\App(dirname(__DIR__)); $app->initialize();
use think\facade\Db;
if(($argv[1]??'')==='worker') {
    $trip=(int)$argv[2]; $key=$argv[3]; $latch=$argv[4];
    for($i=0;$i<100&&!is_file($latch);$i++)usleep(10000);
    try { (new app\service\Ticketing())->issue(['trip_id'=>$trip,'origin_seq'=>0,'destination_seq'=>1,'passenger'=>'Concurrency Test'],$key,'TEST'); echo 'ISSUED'; }
    catch(think\exception\HttpException $e) { echo $e->getStatusCode()===409?'CONFLICT':'ERROR'; }
    exit;
}
$source=Db::table('trips')->where('active',1)->find();
if(!$source)throw new RuntimeException('Import first');
$stops=Db::table('stops')->where('trip_id',$source['id'])->order('seq')->limit(2)->select()->toArray();
unset($source['id']); $source['dimension']='test/'.bin2hex(random_bytes(8)); $source['service_date']='2099-01-01'; $source['capacity']=1;
$trip=(int)Db::table('trips')->insertGetId($source); $children=[]; $latch=dirname(__DIR__).'/runtime/latch-'.$trip;
try {
    Db::table('agencies')->insert(['code'=>'TEST','name'=>'Test agency','issuer_code'=>'998']);
    Db::table('agency_operators')->insert(['agency_code'=>'TEST','operator_code'=>$source['operator_code']]);
    foreach($stops as $seq=>$s) { $s['trip_id']=$trip; $s['seq']=$seq; $s['arrival_at']='2099-01-01 10:0'.$seq.':00.000'; $s['departure_at']='2099-01-01 10:0'.$seq.':30.000'; Db::table('stops')->insert($s); }
    for($i=0;$i<8;$i++) {
        $pipes=[]; $process=proc_open([PHP_BINARY,__FILE__,'worker',(string)$trip,'race-'.$trip.'-'.$i,$latch],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('Worker launch failed');
        fclose($pipes[0]); $children[]=[$process,$pipes];
    }
    file_put_contents($latch,'go'); $results=[];
    foreach($children as [$proc,$pipes]) { $results[]=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);if($err||$exit)throw new RuntimeException('Worker failed: '.$err); }
    $children=[];
    if(count(array_filter($results,fn($s)=>$s==='ISSUED'))!==1||count(array_filter($results,fn($s)=>$s==='CONFLICT'))!==7)throw new RuntimeException('Unexpected race results: '.json_encode($results));
    echo 'PASS Eight concurrent buyers: exactly one issued, seven sold-out conflicts'.PHP_EOL;
} finally {
    foreach($children as [$proc,$pipes]) { proc_terminate($proc);foreach($pipes as $p)if(is_resource($p))fclose($p);proc_close($proc); }
    if(is_file($latch))unlink($latch);
    Db::transaction(function()use($trip){
        $ids=Db::table('coupons')->where('trip_id',$trip)->column('ticket_id');
        if($ids){Db::table('requests')->whereIn('ticket_id',$ids)->delete();Db::table('ticket_events')->whereIn('ticket_id',$ids)->delete();Db::table('coupons')->where('trip_id',$trip)->delete();Db::table('tickets')->whereIn('id',$ids)->delete();}
        Db::table('agency_operators')->where('agency_code','TEST')->delete(); Db::table('agencies')->where('code','TEST')->delete();
        Db::table('stops')->where('trip_id',$trip)->delete();Db::table('trips')->where('id',$trip)->delete();
    });
}

