<?php
declare(strict_types=1);
namespace app\service;
use think\facade\Db;
use think\exception\HttpException;

/** Date means the trip's UTC service_date, including inactive import revisions. */
final class DateCleanup
{
    public function run(string $date,string $scope,bool $preview=true): array {
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,new \DateTimeZone('UTC'));
        if(!$parsed || $parsed->format('Y-m-d')!==$date) throw new HttpException(422,'date must be YYYY-MM-DD (UTC service date)');
        if(!in_array($scope,['all','tickets','timetable'],true)) throw new HttpException(422,'scope must be all, tickets or timetable');
        $lock='mtr-service-date:'.$date;
        if((int)Db::query('SELECT GET_LOCK(?, 10) acquired',[$lock])[0]['acquired']!==1) throw new HttpException(409,'Service date is being imported or cleared');
        try {
            return Db::transaction(function()use($date,$scope,$preview) {
                // Same row locks as issuance, refunds and import: never free inventory mid-sale.
                $tripIds=Db::table('trips')->where('service_date',$date)->order('id')->lock(true)->column('id');
                $ticketIds=$tripIds?Db::table('coupons')->whereIn('trip_id',$tripIds)->distinct(true)->column('ticket_id'):[];
                if($ticketIds) Db::table('tickets')->whereIn('id',$ticketIds)->order('id')->lock(true)->select();
                $counts=['trips'=>count($tripIds),'stops'=>$tripIds?Db::table('stops')->whereIn('trip_id',$tripIds)->count():0,
                    'imports'=>Db::table('imports')->where('service_date',$date)->count(),
                    'tickets'=>count($ticketIds),'coupons'=>$ticketIds?Db::table('coupons')->whereIn('ticket_id',$ticketIds)->count():0,
                    'requests'=>$ticketIds?Db::table('requests')->whereIn('ticket_id',$ticketIds)->count():0,
                    'ticket_events'=>$ticketIds?Db::table('ticket_events')->whereIn('ticket_id',$ticketIds)->count():0,
                    'ticket_tlv'=>$ticketIds?Db::table('ticket_tlv')->whereIn('ticket_id',$ticketIds)->count():0,
                    'ticket_tlv_events'=>$ticketIds?Db::table('ticket_tlv_events')->alias('e')->join('ticket_tlv v','v.id=e.tlv_id')->whereIn('v.ticket_id',$ticketIds)->count():0];
                $blocked=$scope==='timetable' && (bool)$ticketIds;
                // Current model has one coupon, but protect future cross-date multi-coupon tickets.
                if($scope!=='timetable' && $ticketIds && Db::table('coupons')->whereIn('ticket_id',$ticketIds)->whereNotIn('trip_id',$tripIds)->count())
                    throw new HttpException(409,'A ticket also references another service date');
                $affected=$counts;
                foreach($affected as $table=>&$count) {
                    if(($scope==='tickets' && in_array($table,['trips','stops','imports'],true)) ||
                       ($scope==='timetable' && !in_array($table,['trips','stops','imports'],true))) $count=0;
                }
                unset($count);
                if(!$preview) {
                    if($blocked) throw new HttpException(409,'Clear associated tickets first, or use scope all');
                    if($scope!=='timetable' && $ticketIds) {
                        foreach(['requests','ticket_events','coupons'] as $table) Db::table($table)->whereIn('ticket_id',$ticketIds)->delete();
                        Db::table('tickets')->whereIn('id',$ticketIds)->delete();
                    }
                    if($scope!=='tickets') {
                        if($tripIds) {
                            Db::table('stops')->whereIn('trip_id',$tripIds)->delete();
                            Db::table('trips')->whereIn('id',$tripIds)->delete();
                        }
                        Db::table('imports')->where('service_date',$date)->delete();
                    }
                }
                return ['date'=>$date,'scope'=>$scope,'preview'=>$preview,'blocked'=>$blocked,'counts'=>$affected,
                    'date_basis'=>'UTC service_date; all dimensions and import revisions'];
            });
        } finally { Db::query('SELECT RELEASE_LOCK(?)',[$lock]); }
    }
}

