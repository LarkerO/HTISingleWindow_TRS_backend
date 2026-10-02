<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';$app=new think\App(dirname(__DIR__));$app->initialize();
use think\facade\Db;
$suffix=$argv[2]??'';if(!preg_match('/^[A-F0-9]{10}$/',$suffix))throw new RuntimeException('Invalid fixture suffix');
$op='PD'.$suffix;$agency='PA'.$suffix;$dimension='passenger-ui/'.$suffix;$docNumber='UI-'.$suffix;
if(($argv[1]??'')==='cleanup'){
 Db::transaction(function()use($op,$agency,$dimension,$docNumber){
  $tripIds=Db::table('trips')->where('dimension',$dimension)->order('id')->lock(true)->column('id');
  $ticketIds=$tripIds?Db::table('coupons')->whereIn('trip_id',$tripIds)->column('ticket_id'):[];
  if($ticketIds){foreach(['requests','ticket_events','coupons'] as $table)Db::table($table)->whereIn('ticket_id',$ticketIds)->delete();Db::table('tickets')->whereIn('id',$ticketIds)->delete();}
  if($tripIds){Db::table('stops')->whereIn('trip_id',$tripIds)->delete();Db::table('trips')->whereIn('id',$tripIds)->delete();}
  $docIds=Db::table('passenger_documents')->where('document_type','PASSPORT')->whereIn('document_number',[$docNumber,$docNumber.'-EXTRA'])->where('issuing_country','CHN')->column('id');
  if($docIds){Db::table('passenger_document_events')->whereIn('document_id',$docIds)->delete();Db::table('passenger_documents')->whereIn('id',$docIds)->delete();}
  Db::table('agency_operators')->where('agency_code',$agency)->delete();Db::table('agency_operators')->where('operator_code',$op)->delete();
  Db::table('agencies')->where('code',$agency)->delete();Db::table('operators')->where('code',$op)->delete();
 });echo '{}';exit;
}
if(($argv[1]??'')!=='setup')throw new RuntimeException('Unknown fixture action');
$result=Db::transaction(function()use($op,$agency,$dimension,$docNumber){
 $used=Db::table('agencies')->column('issuer_code');$prefix='';for($i=100;$i<999;$i++)if(!in_array((string)$i,$used,true)){$prefix=(string)$i;break;}
 $org=new app\service\Organizations();$org->save('operators',['code'=>$op,'name'=>'Passenger fixture operator']);$org->save('agencies',['code'=>$agency,'name'=>'Passenger fixture agency','issuer_code'=>$prefix]);
 $operatorKey=$org->rotateOperatorKey($op)['api_key'];$agencyKey=$org->rotateKey($agency)['api_key'];
 $doc=(new app\service\PassengerDocuments())->save(['document_type'=>'PASSPORT','document_number'=>$docNumber,'issuing_country'=>'CHN','birth_date'=>'1990-02-03','surname'=>'','given_name'=>'旅客初始名','reason'=>'Fixture registration'],['role'=>'admin']);
 $trip=Db::table('trips')->where('active',1)->order('id')->find();$originalId=$trip['id'];unset($trip['id']);
 $trip['dimension']=$dimension;$trip['service_date']='2099-08-01';$trip['operator_code']=$op;$trip['capacity']=10;$id=(int)Db::table('trips')->insertGetId($trip);
 $source=Db::table('stops')->where('trip_id',$originalId)->order('seq')->select()->toArray();$chosen=[$source[0]];
 foreach($source as $stop)if($stop['station_id']!==$source[0]['station_id']){$chosen[]=$stop;break;}
 if(count($chosen)!==2)throw new RuntimeException('Missing fixture interval');
 foreach($chosen as $seq=>&$stop){$stop['trip_id']=$id;$stop['seq']=$seq;$stop['arrival_at']='2099-08-01 10:0'.$seq.':00';$stop['departure_at']='2099-08-01 10:0'.$seq.':30';Db::table('stops')->insert($stop);}
 return ['operator'=>$op,'agency'=>$agency,'operator_key'=>$operatorKey,'agency_key'=>$agencyKey,'document'=>$doc,'trip_id'=>$id,'date'=>'2099-08-01','origin'=>$chosen[0]['station_id'],'destination'=>$chosen[1]['station_id']];
});
echo json_encode($result,JSON_UNESCAPED_UNICODE);

