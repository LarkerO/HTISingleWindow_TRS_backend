<?php
declare(strict_types=1);
namespace app\service;
use think\facade\Db;
use think\exception\HttpException;
final class DepotOwnership
{
    public function assign(string $dimension,string $depotId,string $operator): array {
        if(!preg_match('~^[A-Za-z0-9_/-]{1,120}$~',$dimension)||!preg_match('/^-?[0-9]{1,19}$/',$depotId))throw new HttpException(422,'Invalid dimension or depot_id');
        $lock='mtr-depot-ownership';
        if((int)Db::query('SELECT GET_LOCK(?, 10) acquired',[$lock])[0]['acquired']!==1)throw new HttpException(409,'Depot ownership or import is being updated');
        try {
            return Db::transaction(function()use($dimension,$depotId,$operator){
                // Lock trips before updating the catalog row, consistent with sale/import.
                $trips=Db::table('trips')->where('dimension',$dimension)->where('depot_id',$depotId)->order('id')->lock(true)->select()->toArray();
                $depot=Db::table('mtr_depots')->where('dimension',$dimension)->where('depot_id',$depotId)->lock(true)->find();
                if(!$depot)throw new HttpException(404,'Depot not found');
                if(!Db::table('operators')->where('code',$operator)->where('active',1)->find())throw new HttpException(422,'Enabled operator required');
                $changed=array_column(array_filter($trips,fn($t)=>$t['operator_code']!==$operator),'id');
                if($changed && Db::table('coupons')->whereIn('trip_id',$changed)->count())throw new HttpException(409,'Depot has issued trips under another operator');
                if($changed)Db::table('trips')->whereIn('id',$changed)->update(['operator_code'=>$operator]);
                Db::table('mtr_depots')->where('dimension',$dimension)->where('depot_id',$depotId)->update(['operator_code'=>$operator]);
                return ['dimension'=>$dimension,'depot_id'=>$depotId,'operator_code'=>$operator,'updated_trips'=>count($changed),'assignment_scope'=>'all routes, all service dates and future imports'];
            });
        } finally {Db::query('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
}
