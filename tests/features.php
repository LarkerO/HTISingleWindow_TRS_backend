<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';$app=new think\App(dirname(__DIR__));$app->initialize();
use think\facade\Db;
use app\mtr\TrainNumber;
use app\service\Organizations;
use app\service\OperatorWhitelist;
use app\service\DepotOwnership;
use app\service\Ticketing;
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
function rejects(callable $fn,int $status):void{try{$fn();}catch(think\exception\HttpException $e){check($e->getStatusCode()===$status,'Expected rejection '.$status);return;}throw new RuntimeException('Expected rejection');}
foreach(['G2113||服铁沁京局担当G2113'=>'G2113','C8665||担当路局: 颐恒局'=>'C8665',' G-0104 客运|Guangyang'=>'G0104','D7705||服铁沁京局担当'=>'D7705','S997||市域'=>'S997','G306 滨海北-广阳'=>'G306','快速|c 123'=>'C123'] as $name=>$expected)check(TrainNumber::extract($name)===$expected,'Extract '.$expected);
foreach(['K123','G1A','GCT5號車廠','广急SY普通场3','G123/G124','Line S1'] as $name)check(TrainNumber::extract($name)===null,'Skip unsupported/ambiguous '.$name);
$groups=Db::query('SELECT train_number,COUNT(*) total FROM trips WHERE active=1 AND service_date=? AND train_number IS NOT NULL GROUP BY train_number HAVING COUNT(*)>1 ORDER BY total DESC',['2026-10-04']);
check((bool)$groups,'Commercial numbers repeat on a service date');
$number=$groups[0]['train_number'];
$source=Db::table('trips')->where('active',1)->where('service_date','2026-10-04')->where('train_number',$number)->order('id')->find();
$stops=Db::table('stops')->where('trip_id',$source['id'])->order('seq')->select()->toArray();
$journeys=(new Ticketing())->search($stops[0]['station_id'],$stops[1]['station_id'],substr($stops[0]['departure_at'],0,10),['train_number'=>$number]);
check(count($journeys)>1&&count(array_unique(array_column($journeys,'id')))>1,'Same-number interval search returns separate timed IDs');
$from=substr($journeys[0]['departure_at'],11,8);$window=(new Ticketing())->search($stops[0]['station_id'],$stops[1]['station_id'],substr($stops[0]['departure_at'],0,10),['train_number'=>$number,'time_from'=>$from]);
foreach($window as $j)if($j['train_number']!==$number||substr($j['departure_at'],11,8)<$from)throw new RuntimeException('Invalid filtered departure');
check((bool)$window,'Number/time filter respects selected boarding departure');
rejects(fn()=>(new Ticketing())->search($stops[0]['station_id'],$stops[1]['station_id'],'2026-10-04',['time_from'=>'18:00','time_to'=>'17:00']),422);
$before=Db::table('trips')->count();$suffix=strtoupper(bin2hex(random_bytes(3)));$op='F'.$suffix;$other='H'.$suffix;$agency='N'.$suffix;$dimension='features/'.$suffix;
$used=Db::table('agencies')->column('issuer_code');$prefix='';for($i=100;$i<999;$i++)if(!in_array((string)$i,$used,true)){$prefix=(string)$i;break;}
Db::startTrans();
try{
 $org=new Organizations();$org->save('operators',['code'=>$op,'name'=>'Open operator']);
 $org->save('operators',['code'=>$other,'name'=>'Other operator']);
 $org->save('agencies',['code'=>$agency,'name'=>'Feature agency','issuer_code'=>$prefix]);
 check((int)Db::table('operators')->where('code',$op)->value('whitelist_enabled')===0,'New operator defaults to optional whitelist disabled');
 $depot=Db::table('mtr_depots')->where('dimension',$source['dimension'])->where('depot_id',$source['depot_id'])->find();$depot['dimension']=$dimension;$depot['operator_code']=null;Db::table('mtr_depots')->insert($depot);
 $trip=$source;unset($trip['id']);$trip['dimension']=$dimension;$trip['service_date']='2098-01-01';$trip['operator_code']='LOCAL';$trip['capacity']=10;$id=(int)Db::table('trips')->insertGetId($trip);
 foreach(array_slice($stops,0,2) as $seq=>$s){$s['trip_id']=$id;$s['seq']=$seq;$s['arrival_at']='2098-01-01 10:0'.$seq.':00';$s['departure_at']='2098-01-01 10:0'.$seq.':30';Db::table('stops')->insert($s);}
 $owner=new DepotOwnership();$owner->assign($dimension,$source['depot_id'],$op);
 check(Db::table('trips')->where('id',$id)->value('operator_code')===$op,'Depot assignment updates its existing trips');
 $shown=app\service\TripIdentity::present(Db::table('trips')->where('id',$id)->find());
 check($shown['operator_assignment']==='depot'&&$shown['depot_operator_code']===$op,'Displayed trip identifies depot ownership');
 rejects(fn()=>$org->assignTrip($id,$other),409);
 $ticketing=new Ticketing();$body=['trip_id'=>$id,'origin_seq'=>0,'destination_seq'=>1,'passenger'=>'Feature test'];
 $ticket=$ticketing->issue($body,$suffix.'-open',$agency);
 check($ticket['status']==='OPEN','Open operator accepts enabled agency without a list entry');
 $org->save('operators',['name'=>'Open operator','whitelist_enabled'=>1],$op);
 rejects(fn()=>$ticketing->issue($body,$suffix.'-closed',$agency),403);
 $white=new OperatorWhitelist();$white->member($op,$agency,true);$white->member($op,$agency,true);
 check(count($white->get($op)['agencies'])===1,'Whitelist add is idempotent');
 check($ticketing->issue($body,$suffix.'-allowed',$agency)['status']==='OPEN','Whitelisted agency can issue');
 $white->member($op,$agency,false);
 rejects(fn()=>$ticketing->issue($body,$suffix.'-removed',$agency),403);
 check($ticketing->refund($ticket['ticket_number'],$agency)['status']==='RFND','Whitelist removal preserves existing refund access');
 $org->save('operators',['name'=>'Open operator','whitelist_enabled'=>0],$op);
 check($ticketing->issue($body,$suffix.'-reopen',$agency)['status']==='OPEN','Disabling whitelist restores open sales without rebuilding list');
 rejects(fn()=>$owner->assign($dimension,$source['depot_id'],$other),409);
 check(Db::table('mtr_depots')->where('dimension',$dimension)->where('depot_id',$source['depot_id'])->value('operator_code')===$op,'Issued trip protection rolls back depot reassignment');
 $world=(new app\mtr\WorldReader())->read('../world/mtr/minecraft/overworld');$plan=(new app\mtr\Timetable())->build($world);
 $sample=array_values(array_filter($plan['trips'],fn($t)=>$t['depot_id']===$source['depot_id']))[0];$plan['trips']=[$sample];
 (new app\service\Importer())->import($world,$plan,$dimension,'2098-01-02',str_repeat('0',64),'LOCAL',3,100,true);
 $imported=Db::table('trips')->where('dimension',$dimension)->where('service_date','2098-01-02')->find();
 check($imported['operator_code']===$op,'Future import inherits depot owner instead of command default');
 check(Db::table('mtr_depots')->where('dimension',$dimension)->where('depot_id',$source['depot_id'])->value('operator_code')===$op,'Catalog sync preserves depot ownership');
}finally{Db::rollback();}
check(Db::table('trips')->count()===$before,'Feature database changes rolled back');


