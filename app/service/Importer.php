<?php
declare(strict_types=1);
namespace app\service;
use think\facade\Db;
final class Importer
{
    public function import(array $data,array $plan,string $dimension,string $date,string $hash,string $operator,int $capacity,int $fare,bool $merge=false): int {
        $dateLock='mtr-service-date:'.$date;
        if((int)Db::query('SELECT GET_LOCK(?, 10) acquired',[$dateLock])[0]['acquired']!==1) throw new \RuntimeException('Service date is being imported or cleared');
        $ownershipLock='mtr-depot-ownership';
        try {
        if((int)Db::query('SELECT GET_LOCK(?, 10) acquired',[$ownershipLock])[0]['acquired']!==1) throw new \RuntimeException('Depot ownership is being updated');
        try {
        $lock='mtr-import:'.substr(hash('sha256',$dimension.':'.$date),0,48);
        if((int)Db::query('SELECT GET_LOCK(?, 10) acquired',[$lock])[0]['acquired']!==1) throw new \RuntimeException('Another import is running');
        try { return Db::transaction(function()use($data,$plan,$dimension,$date,$hash,$operator,$capacity,$fare,$merge){
            // Lock the same physical trips as issue/refund before checking issued tickets.
            $existing=Db::table('trips')->where('dimension',$dimension)->where('service_date',$date)->where('active',1)->order('id')->lock(true)->select()->toArray();
            $byKey=array_column($existing,null,'trip_key');
            // Never duplicate physical inventory after any ticket has been issued.
            if(Db::table('coupons')->alias('c')->join('trips t','t.id=c.trip_id')->where('t.dimension',$dimension)->where('t.service_date',$date)->count()) throw new \RuntimeException('Cannot replace a service date with issued tickets');
            if(!Db::table('operators')->where('code',$operator)->where('active',1)->find()) throw new \RuntimeException('Create an enabled operator before importing');
            Catalog::sync($data,$plan,$dimension);
            $id=(int)Db::table('imports')->insertGetId(['dimension'=>$dimension,'service_date'=>$date,'source_hash'=>$hash,'report'=>json_encode(['counts'=>$plan['counts'],'trip_count'=>count($plan['trips']),'warnings'=>$plan['warnings'],'clock'=>$plan['clock']],JSON_UNESCAPED_UNICODE)]);
            Db::table('trips')->where('dimension',$dimension)->where('service_date',$date)->update(['active'=>0]);
            foreach($data['stations'] as $s) Db::table('stations')->duplicate(['name'=>$s['name']])->insert(['id'=>$dimension.':'.$s['id'],'mtr_id'=>(string)$s['id'],'dimension'=>$dimension,'name'=>$s['name']]);
            $midnight=new \DateTimeImmutable($date.' 00:00:00',new \DateTimeZone('UTC'));
            $owners=Db::table('mtr_depots')->where('dimension',$dimension)->whereNotNull('operator_code')->column('operator_code','depot_id');
            foreach($plan['trips'] as $trip) {
                $values=['import_id'=>$id,'dimension'=>$dimension,'service_date'=>$date,'trip_key'=>$trip['key'],'operator_code'=>$operator,'name'=>$trip['name'],'train_number'=>$trip['train_number']??\app\mtr\TrainNumber::extract($trip['name']),'depot_id'=>$trip['depot_id'],'siding_id'=>$trip['siding_id'],'run_number'=>$trip['run_number'],'route_ids'=>json_encode($trip['route_ids']),'capacity'=>$capacity,'fare_per_segment'=>$fare,'active'=>1];
                // A legacy trip covered the whole depot cycle. New directional trips
                // inherit its settings, but cannot reuse one ID for several route legs.
                if($merge && isset($trip['physical_key'],$byKey[$trip['physical_key']])) {
                    foreach(['operator_code','capacity','fare_per_segment'] as $field) $values[$field]=$byKey[$trip['physical_key']][$field];
                }
                if(isset($owners[$trip['depot_id']])) {
                    if(!Db::table('operators')->where('code',$owners[$trip['depot_id']])->where('active',1)->find()) throw new \RuntimeException('Depot owner is disabled');
                    $values['operator_code']=$owners[$trip['depot_id']];
                }
                if($merge&&isset($byKey[$trip['key']])) {
                    $prior=$byKey[$trip['key']];$tid=$prior['id'];
                    foreach(['operator_code','capacity','fare_per_segment'] as $field) if($field!=='operator_code'||!isset($owners[$trip['depot_id']])) $values[$field]=$prior[$field];
                    Db::table('trips')->where('id',$tid)->update($values);
                    Db::table('stops')->where('trip_id',$tid)->delete();
                } else $tid=Db::table('trips')->insertGetId($values);
                $rows=[];
                foreach($trip['stops'] as $seq=>$s) {
                    $format=fn($offset)=>$midnight->modify('+'.intdiv($trip['departure_ms']+$offset,1000).' seconds')->format('Y-m-d H:i:s').sprintf('.%03d',($trip['departure_ms']+$offset)%1000);
                    $rows[]=['trip_id'=>$tid,'seq'=>$seq,'station_id'=>$dimension.':'.$s['station_id'],'platform_id'=>$s['platform_id'],'arrival_at'=>$format($s['arrival_offset_ms']),'departure_at'=>$format($s['departure_offset_ms'])];
                }
                Db::table('stops')->insertAll($rows);
            }
            return $id;
        }); } finally { Db::query('SELECT RELEASE_LOCK(?)',[$lock]); }
        } finally { Db::query('SELECT RELEASE_LOCK(?)',[$ownershipLock]); }
        } finally { Db::query('SELECT RELEASE_LOCK(?)',[$dateLock]); }
    }
}









