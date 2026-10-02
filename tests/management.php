<?php
require dirname(__DIR__).'/vendor/autoload.php';
$app=new think\App(dirname(__DIR__));$app->initialize();
use think\facade\Db;
$port=18765;$pipes=[];$proc=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',dirname(__DIR__).'/public',dirname(__DIR__).'/public/router.php'],[0=>['pipe','r'],1=>['file',dirname(__DIR__).'/runtime/management-test.log','a'],2=>['file',dirname(__DIR__).'/runtime/management-test.log','a']],$pipes,dirname(__DIR__));
function callApi(string $path,string $key,string $method='GET',?array $body=null,array $headers=[]): array {
    global $port;$c=curl_init('http://127.0.0.1:'.$port.$path);
    curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>array_merge(['X-API-Key: '.$key,'Content-Type: application/json'],$headers)]);
    if($body!==null)curl_setopt($c,CURLOPT_POSTFIELDS,json_encode($body));
    $raw=curl_exec($c);$status=curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);return [$status,json_decode($raw?:'',true),$raw];
}
function expect(int $status,array $response,string $label): array { if($response[0]!==$status)throw new RuntimeException($label.': '.json_encode($response));echo 'PASS '.$label.PHP_EOL;return $response[1]['data']??[]; }
$admin=(string)config('ticketing.api_key');$suffix=strtoupper(bin2hex(random_bytes(3)));$op='T'.$suffix;$agency='A'.$suffix;$trip=0;$ticketIds=[];
$used=Db::table('agencies')->column('issuer_code');$prefix='';for($i=100;$i<999;$i++){if(!in_array((string)$i,$used,true)){$prefix=(string)$i;break;}}
try {
    for($i=0;$i<30;$i++){usleep(100000);if(callApi('/health',$admin)[0]===200)break;}
    expect(200,callApi('/api/v1/admin/operators',$admin),'Admin organization list');
    expect(201,callApi('/api/v1/admin/operators',$admin,'POST',['code'=>$op,'name'=>'Test operator','whitelist_enabled'=>1]),'Create operator');
    expect(201,callApi('/api/v1/admin/agencies',$admin,'POST',['code'=>$agency,'name'=>'Test agency','issuer_code'=>$prefix]),'Create agency');
    expect(409,callApi('/api/v1/admin/agencies',$admin,'POST',['code'=>$agency.'B','name'=>'Duplicate stock','issuer_code'=>$prefix]),'Unique ticket prefix');
    expect(200,callApi('/api/v1/admin/agencies/'.$agency.'/operators',$admin,'PUT',['operators'=>[$op]]),'Set sales authorization');
    $result=expect(200,callApi('/api/v1/admin/agencies/'.$agency.'/key',$admin,'POST',[]),'Generate agency key');$key=$result['api_key'];
    expect(200,callApi('/api/v1/me',$key),'Agency key authentication');

    expect(403,callApi('/api/v1/admin/operators/'.$op.'/agencies',$key),'Agency cannot manage operator whitelist');
    $wl=expect(200,callApi('/api/v1/admin/operators/'.$op.'/agencies',$admin),'Operator whitelist list');
    if(!$wl['enabled']||count($wl['agencies'])!==1)throw new RuntimeException('Whitelist list/mode mismatch');
    expect(200,callApi('/api/v1/admin/operators/'.$op.'/agencies/'.$agency,$admin,'DELETE'),'Remove whitelist member');
    expect(200,callApi('/api/v1/admin/operators/'.$op.'/agencies',$admin,'POST',['agency_code'=>$agency]),'Add whitelist member');

    expect(403,callApi('/api/v1/admin/operators',$key),'Agency cannot access admin management');
    expect(403,callApi('/api/v1/admin/clear-date',$key,'POST',['date'=>'2097-05-11','scope'=>'all']),'Agency cannot clear dates');
    $preview=expect(200,callApi('/api/v1/admin/clear-date',$admin,'POST',['date'=>'2026-10-04','scope'=>'all']),'Admin date-cleanup defaults to preview');
    if(!$preview['preview'])throw new RuntimeException('Cleanup preview default missing');
    expect(422,callApi('/api/v1/admin/clear-date',$admin,'POST',['date'=>'2026-10-04','scope'=>'all','preview'=>false]),'Deletion requires matching confirm_date');
    expect(422,callApi('/api/v1/admin/clear-date',$admin,'POST',['date'=>'2026-02-30','scope'=>'all']),'Invalid cleanup date rejected');

    expect(403,callApi('/api/v1/me',$key,'GET',null,['X-Agency-Code: LOCAL']),'Agency cannot impersonate another issuer');
    $source=Db::table('trips')->where('active',1)->find();$stops=Db::table('stops')->where('trip_id',$source['id'])->order('seq')->limit(2)->select()->toArray();$depot=Db::table('mtr_depots')->where('dimension',$source['dimension'])->where('depot_id',$source['depot_id'])->find();$depot['dimension']='test/'.$suffix;$depot['present']=0;$depot['operator_code']=null;Db::table('mtr_depots')->insert($depot);unset($source['id']);$source['dimension']='test/'.$suffix;$source['service_date']='2099-01-01';$source['operator_code']=$op;$source['capacity']=10;$trip=(int)Db::table('trips')->insertGetId($source);
    foreach($stops as $seq=>$s){$s['trip_id']=$trip;$s['seq']=$seq;$s['arrival_at']='2099-01-01 10:0'.$seq.':00.000';$s['departure_at']='2099-01-01 10:0'.$seq.':30.000';Db::table('stops')->insert($s);}

    expect(403,callApi('/api/v1/admin/depots/operator',$key,'PUT',['dimension'=>'test/'.$suffix,'depot_id'=>$source['depot_id'],'operator_code'=>$op]),'Agency cannot assign depot ownership');
    expect(200,callApi('/api/v1/admin/depots/operator',$admin,'PUT',['dimension'=>'test/'.$suffix,'depot_id'=>$source['depot_id'],'operator_code'=>$op]),'Assign depot owner through HTTP');
    $detail=expect(200,callApi('/api/v1/trips/'.$trip,$admin),'Depot-owned trip detail');
    if($detail['operator_assignment']!=='depot')throw new RuntimeException('Depot ownership annotation missing');
    $numbered=Db::table('trips')->where('active',1)->where('service_date','2026-10-04')->whereNotNull('train_number')->order('id')->find();
    $found=expect(200,callApi('/api/v1/trips?date=2026-10-04&train_number='.$numbered['train_number'],$admin),'HTTP number/date filter');
    foreach($found as $row)if($row['train_number']!==$numbered['train_number'])throw new RuntimeException('Number filter mismatch');
    expect(422,callApi('/api/v1/trips?date=2026-10-04&time_from=18:00&time_to=17:00',$admin),'HTTP invalid time window');

    $body=['trip_id'=>$trip,'origin_seq'=>0,'destination_seq'=>1,'passenger'=>'HTTP Test'];
    $ticket=expect(201,callApi('/api/v1/tickets',$key,'POST',$body,['Idempotency-Key: '.$suffix.'-issue']),'Agency authorized ticket issuance');
    if(substr($ticket['ticket_number'],0,3)!==$prefix)throw new RuntimeException('Wrong issuer prefix');echo 'PASS Ticket uses agency prefix'.PHP_EOL;
    expect(404,callApi('/api/v1/tickets/'.$ticket['ticket_number'],$admin),'Ticket remains isolated from default agency');
    expect(200,callApi('/api/v1/admin/agencies/'.$agency.'/operators',$admin,'PUT',['operators'=>[]]),'Revoke sales authorization');
    expect(403,callApi('/api/v1/tickets',$key,'POST',$body,['Idempotency-Key: '.$suffix.'-blocked']),'Revoked agency cannot issue new tickets');
    expect(200,callApi('/api/v1/tickets/'.$ticket['ticket_number'].'/refund',$key,'POST',[]),'Revoked sales grant still permits existing refund');
    expect(409,callApi('/api/v1/admin/trips/'.$trip.'/operator',$admin,'PUT',['operator_code'=>'LOCAL']),'Issued trip operator remains immutable');
    $new=expect(200,callApi('/api/v1/admin/agencies/'.$agency.'/key',$admin,'POST',[]),'Rotate agency key');
    expect(401,callApi('/api/v1/me',$key),'Previous key immediately invalid');$key=$new['api_key'];
    expect(200,callApi('/api/v1/admin/agencies/'.$agency,$admin,'PUT',['name'=>'Test agency disabled','active'=>0]),'Disable agency');
    expect(401,callApi('/api/v1/me',$key),'Disabled agency cannot authenticate');
    expect(200,callApi('/api/v1/admin/operators/'.$op,$admin,'PUT',['name'=>'Test operator disabled','active'=>0]),'Disable operator');
    expect(200,callApi('/test.html',$admin),'Test HTML served');
} finally {
    proc_terminate($proc);foreach($pipes as $p)fclose($p);proc_close($proc);
    Db::transaction(function()use($trip,$agency,$op){
        if($trip){$ids=Db::table('coupons')->where('trip_id',$trip)->column('ticket_id');if($ids){Db::table('requests')->whereIn('ticket_id',$ids)->delete();Db::table('ticket_events')->whereIn('ticket_id',$ids)->delete();Db::table('coupons')->where('trip_id',$trip)->delete();Db::table('tickets')->whereIn('id',$ids)->delete();}Db::table('stops')->where('trip_id',$trip)->delete();Db::table('trips')->where('id',$trip)->delete();}
        Db::table('mtr_depots')->where('dimension','test/'.substr($op,1))->delete();
        Db::table('agency_operators')->where('agency_code',$agency)->delete();Db::table('agencies')->where('code',$agency)->delete();Db::table('operators')->where('code',$op)->delete();
    });
}
echo 'Management tests passed; temporary organizations and tickets removed.'.PHP_EOL;



