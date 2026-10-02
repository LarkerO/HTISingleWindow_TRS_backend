<?php
declare(strict_types=1);
namespace app\service;
use think\facade\Db;
use think\exception\HttpException;
final class OperatorWhitelist
{
    public function get(string $operator): array {
        $op=Db::table('operators')->where('code',$operator)->find();
        if(!$op)throw new HttpException(404,'Operator not found');
        $agencies=Db::table('agency_operators')->alias('w')->join('agencies a','a.code=w.agency_code')
            ->where('w.operator_code',$operator)->field('a.code,a.name,a.active')->order('a.code')->select()->toArray();
        return ['operator_code'=>$operator,'enabled'=>(bool)$op['whitelist_enabled'],'agencies'=>$agencies];
    }
    public function member(string $operator,string $agency,bool $add): array {
        Db::transaction(function()use($operator,$agency,$add){
            // Issuance locks agency, then operator; whitelist updates use the same order.
            if(!Db::table('agencies')->where('code',$agency)->lock(true)->find())throw new HttpException(422,'Unknown agency');
            if(!Db::table('operators')->where('code',$operator)->lock(true)->find())throw new HttpException(404,'Operator not found');
            $query=Db::table('agency_operators')->where('agency_code',$agency)->where('operator_code',$operator);
            if($add) {
                if(!$query->find()) Db::table('agency_operators')->insert(['agency_code'=>$agency,'operator_code'=>$operator]);
            } else $query->delete();
        });
        return $this->get($operator);
    }
}
