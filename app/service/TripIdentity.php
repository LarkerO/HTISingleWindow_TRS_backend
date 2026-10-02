<?php
declare(strict_types=1);
namespace app\service;
use think\facade\Db;
/** Human-readable run identity; database IDs remain the API's immutable keys. */
final class TripIdentity
{
    public static function present(array $trip): array {
        $trip['train_number']=$trip['train_number']??\app\mtr\TrainNumber::extract($trip['name']);
        $parts=explode('||',$trip['name'],2);$note=$parts[1]??$parts[0];
        $trip['duty']=str_contains($note,'担当')?$note:'';
        $depot=Db::table('mtr_depots')->where('dimension',$trip['dimension'])->where('depot_id',$trip['depot_id'])->find();
        $trip['depot_operator_code']=$depot['operator_code']??null;
        $trip['operator_assignment']=$trip['depot_operator_code']!==null && $trip['depot_operator_code']===$trip['operator_code']?'depot':'trip';
        $trip['run_number']=(int)$trip['run_number'];
        $trip['trip_code']=$trip['depot_id'].'+'.$trip['siding_id'].'+'.$trip['run_number'];
        return $trip;
    }
    public static function migrate(): void {
        Db::execute('ALTER TABLE trips ADD COLUMN IF NOT EXISTS run_number INT UNSIGNED NULL AFTER siding_id');
        // Same siding has the same first-stop offset. Its first-stop time gives
        // the original depot departure order without modifying any sold trip ID.
        Db::execute('UPDATE trips t JOIN (
            SELECT t0.id, ROW_NUMBER() OVER (
                PARTITION BY t0.import_id,t0.dimension,t0.service_date,t0.depot_id,t0.siding_id
                ORDER BY s.departure_at,t0.id
            ) AS ordinal
            FROM trips t0 LEFT JOIN stops s ON s.trip_id=t0.id AND s.seq=0
        ) ranked ON ranked.id=t.id SET t.run_number=ranked.ordinal WHERE t.run_number IS NULL');
    }
}


