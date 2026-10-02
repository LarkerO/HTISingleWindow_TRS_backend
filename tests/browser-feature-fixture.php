<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';$app=new think\App(dirname(__DIR__));$app->initialize();
use think\facade\Db;
$suffix=$argv[2]??'';if(!preg_match('/^[A-F0-9]{10}$/',$suffix))throw new RuntimeException('Invalid fixture suffix');
$op='UI'.$suffix;$agency='UA'.$suffix;$dimension='browser-features/'.$suffix;$id='-999';
if(($argv[1]??'')==='cleanup'){
 Db::transaction(function()use($op,$agency,$dimension){
  if(Db::table('trips')->where('dimension',$dimension)->count())throw new RuntimeException('Unexpected fixture trips');
  Db::table('mtr_depot_sidings')->where('dimension',$dimension)->delete();Db::table('mtr_depots')->where('dimension',$dimension)->delete();
  Db::table('agency_operators')->where('agency_code',$agency)->delete();Db::table('agency_operators')->where('operator_code',$op)->delete();
  Db::table('agencies')->where('code',$agency)->delete();Db::table('operators')->where('code',$op)->delete();
 });echo "{}";exit;
}
if(($argv[1]??'')!=='setup')throw new RuntimeException('Unknown fixture action');
$result=Db::transaction(function()use($op,$agency,$dimension,$id){
 $used=Db::table('agencies')->column('issuer_code');$prefix='';for($i=100;$i<999;$i++)if(!in_array((string)$i,$used,true)){$prefix=(string)$i;break;}
 $org=new app\service\Organizations();$org->save('operators',['code'=>$op,'name'=>'UI operator '.$op]);$org->save('agencies',['code'=>$agency,'name'=>'UI agency '.$agency,'issuer_code'=>$prefix]);
 $depot=Db::table('mtr_depots')->where('present',1)->order('depot_id')->find();$depot['dimension']=$dimension;$depot['depot_id']=$id;$depot['name']='UI depot '.$op;$depot['operator_code']=null;$depot['route_ids']='[]';$depot['departures']='[]';$depot['planning_reason']='No configured routes';$depot['planned_trip_count']=0;$depot['planning_status']='unconfigured';Db::table('mtr_depots')->insert($depot);
 $sample=Db::table('trips')->where('active',1)->where('service_date','2026-10-04')->whereNotNull('train_number')->order('id')->find();
 $stops=Db::table('stops')->where('trip_id',$sample['id'])->order('seq')->limit(2)->select()->toArray();
 return ['operator'=>$op,'agency'=>$agency,'dimension'=>$dimension,'depot_id'=>$id,'number'=>$sample['train_number'],'date'=>'2026-10-04','origin'=>$stops[0]['station_id'],'destination'=>$stops[1]['station_id'],'departure'=>$stops[0]['departure_at']];
});
echo json_encode($result,JSON_UNESCAPED_UNICODE);
