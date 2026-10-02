<?php
declare(strict_types=1);
namespace app\service;
use think\facade\Db;
use think\exception\HttpException;
final class Organizations
{
    public static function seed(): void {
        foreach(Db::table('trips')->distinct(true)->column('operator_code') as $code)
            if(!Db::table('operators')->where('code',$code)->find()) Db::table('operators')->insert(['code'=>$code,'name'=>$code==='LOCAL'?'默认运营方':$code]);
        if(!Db::table('operators')->where('code','LOCAL')->find()) Db::table('operators')->insert(['code'=>'LOCAL','name'=>'默认运营方']);
        $code=(string)config('ticketing.agency_code');
        if(!Db::table('agencies')->where('code',$code)->find()) {
            Db::table('agencies')->insert(['code'=>$code,'name'=>'默认出票方','issuer_code'=>(string)config('ticketing.issuer_code')]);
            foreach(Db::table('operators')->column('code') as $op) Db::table('agency_operators')->insert(['agency_code'=>$code,'operator_code'=>$op]);
        }
    }
    public function list(string $kind): array {
        if($kind==='operators') {
            $rows=Db::table('operators')->field('code,name,contact,active,whitelist_enabled,created_at')->order('code')->select()->toArray();
            foreach($rows as &$row)$row['has_api_key']=(bool)Db::table('operators')->where('code',$row['code'])->value('api_key_hash');
            return $rows;
        }
        $rows=Db::table('agencies')->field('code,name,issuer_code,contact,active,created_at')->order('code')->select()->toArray();
        foreach($rows as &$row) {
            $row['operators']=Db::table('agency_operators')->where('agency_code',$row['code'])->column('operator_code');
            $row['has_api_key']=(bool)Db::table('agencies')->where('code',$row['code'])->value('api_key_hash');
        }
        return $rows;
    }
    private function text(array $body,string $key,int $max,bool $required=false): string {
        if(!isset($body[$key])||!is_string($body[$key])) { if($required) throw new HttpException(422,$key.' is required'); return ''; }
        $v=trim($body[$key]); if(mb_strlen($v)>$max||($required&&$v==='')) throw new HttpException(422,'Invalid '.$key); return $v;
    }
    public function save(string $kind,array $body,?string $code=null): array {
        if(!in_array($kind,['operators','agencies'],true)) throw new HttpException(404,'Unknown organization type');
        $creating=$code===null;
        $code=$code??$this->text($body,'code',$kind==='operators'?16:32,true);
        if(!preg_match($kind==='operators'?'/^[A-Z0-9]{1,16}$/':'/^[A-Z0-9_-]{1,32}$/',$code)) throw new HttpException(422,'Invalid organization code');
        $values=['name'=>$this->text($body,'name',160,true),'contact'=>$this->text($body,'contact',255)];
        if(isset($body['active'])&&!in_array($body['active'],[0,1],true)) throw new HttpException(422,'active must be 0 or 1');
        $values['active']=$body['active']??1;
        if($kind==='operators') {
            if(isset($body['whitelist_enabled'])&&!in_array($body['whitelist_enabled'],[0,1],true))throw new HttpException(422,'whitelist_enabled must be 0 or 1');
            if(isset($body['whitelist_enabled']))$values['whitelist_enabled']=$body['whitelist_enabled'];
            elseif($creating)$values['whitelist_enabled']=0;
        }
        if($creating&&$kind==='agencies') { $values['issuer_code']=$this->text($body,'issuer_code',3,true); if(!preg_match('/^\d{3}$/',$values['issuer_code'])) throw new HttpException(422,'issuer_code must be three digits'); }
        try {
            Db::transaction(function()use($kind,$code,$values,$creating,$body){
                $existing=Db::table($kind)->where('code',$code)->lock(true)->find();
                if($creating) { if($existing) throw new HttpException(409,'Organization code already exists'); Db::table($kind)->insert(array_merge(['code'=>$code],$values)); }
                else { if(!$existing) throw new HttpException(404,'Organization not found'); if(isset($body['issuer_code'])&&$kind==='agencies'&&$body['issuer_code']!==$existing['issuer_code']) throw new HttpException(422,'Issuer prefix is immutable'); Db::table($kind)->where('code',$code)->update($values); }
            });
        } catch(\think\db\exception\PDOException $e) { if(str_contains($e->getMessage(),'Duplicate')) throw new HttpException(409,'Code or issuer prefix already exists'); throw $e; }
        foreach($this->list($kind) as $r) if($r['code']===$code) return $r;
        throw new HttpException(500,'Organization save failed');
    }
    public function grants(string $agency,array $codes): array {
        if(count($codes)>1000) throw new HttpException(422,'Too many operator codes');
        foreach($codes as $code) if(!is_string($code)||!Db::table('operators')->where('code',$code)->find()) throw new HttpException(422,'Unknown operator code');
        Db::transaction(function()use($agency,$codes){
            if(!Db::table('agencies')->where('code',$agency)->lock(true)->find()) throw new HttpException(404,'Agency not found');
            Db::table('operators')->whereIn('code',$codes)->order('code')->lock(true)->select();
            Db::table('agency_operators')->where('agency_code',$agency)->delete();
            foreach(array_unique($codes) as $code) Db::table('agency_operators')->insert(['agency_code'=>$agency,'operator_code'=>$code]);
        });
        return ['agency_code'=>$agency,'operators'=>array_values(array_unique($codes))];
    }
    public function rotateKey(string $code): array {
        $key=bin2hex(random_bytes(32));
        Db::transaction(function()use($code,$key){
            if(!Db::table('agencies')->where('code',$code)->lock(true)->find()) throw new HttpException(404,'Agency not found');
            Db::table('agencies')->where('code',$code)->update(['api_key_hash'=>hash('sha256',$key)]);
        });
        return ['agency_code'=>$code,'api_key'=>$key];
    }
    public function rotateOperatorKey(string $code): array {
        $key=bin2hex(random_bytes(32));
        Db::transaction(function()use($code,$key){
            if(!Db::table('operators')->where('code',$code)->lock(true)->find())throw new HttpException(404,'Operator not found');
            Db::table('operators')->where('code',$code)->update(['api_key_hash'=>hash('sha256',$key)]);
        });
        return ['operator_code'=>$code,'api_key'=>$key];
    }
    public function assignTrip(int $id,string $operator): void {
        Db::transaction(function()use($id,$operator){
            $trip=Db::table('trips')->where('id',$id)->lock(true)->find();
            if(!$trip) throw new HttpException(404,'Trip not found');
            if(!Db::table('operators')->where('code',$operator)->where('active',1)->find()) throw new HttpException(422,'Enabled operator required');
            $owner=Db::table('mtr_depots')->where('dimension',$trip['dimension'])->where('depot_id',$trip['depot_id'])->value('operator_code');
            if($owner!==null && $owner!==$operator)throw new HttpException(409,'Trip belongs to depot owner; reassign the depot instead');
            if(Db::table('coupons')->where('trip_id',$id)->count()) throw new HttpException(409,'Issued trip operator cannot be changed');
            Db::table('trips')->where('id',$id)->update(['operator_code'=>$operator]);
        });
    }
}


