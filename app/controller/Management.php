<?php
namespace app\controller;
use think\Request;
use think\exception\HttpException;
use app\service\Organizations;
final class Management
{
    private function body(Request $r): array { $b=json_decode($r->getContent(),true); if(!is_array($b)||array_is_list($b)) throw new HttpException(422,'JSON object required'); return $b; }
    public function clearDate(Request $r) {
        $b=$this->body($r);
        if(!isset($b['date'],$b['scope']) || !is_string($b['date']) || !is_string($b['scope']) ||
           (isset($b['preview']) && !is_bool($b['preview']))) throw new HttpException(422,'date, scope and boolean preview required');
        $preview=$b['preview']??true;
        if(!$preview && ($b['confirm_date']??null)!==$b['date']) throw new HttpException(422,'confirm_date must match date');
        return json(['data'=>(new \app\service\DateCleanup())->run($b['date'],$b['scope'],$preview)]);
    }
    public function whitelist(string $code) {return json(['data'=>(new \app\service\OperatorWhitelist())->get($code)]);}
    public function addWhitelist(Request $r,string $code) {
        $b=$this->body($r);
        if(!isset($b['agency_code'])||!is_string($b['agency_code']))throw new HttpException(422,'agency_code required');
        return json(['data'=>(new \app\service\OperatorWhitelist())->member($code,$b['agency_code'],true)]);
    }
    public function removeWhitelist(string $code,string $agency) {return json(['data'=>(new \app\service\OperatorWhitelist())->member($code,$agency,false)]);}
    public function assignDepot(Request $r) {
        $b=$this->body($r);
        foreach(['dimension','depot_id','operator_code'] as $key)if(!isset($b[$key])||!is_string($b[$key]))throw new HttpException(422,$key.' must be a string');
        return json(['data'=>(new \app\service\DepotOwnership())->assign($b['dimension'],$b['depot_id'],$b['operator_code'])]);
    }
    public function operators() {return json(['data'=>(new Organizations())->list('operators')]);}
    public function agencies() {return json(['data'=>(new Organizations())->list('agencies')]);}
    public function createOperator(Request $r) {return json(['data'=>(new Organizations())->save('operators',$this->body($r))],201);}
    public function updateOperator(Request $r,string $code) {return json(['data'=>(new Organizations())->save('operators',$this->body($r),$code)]);}
    public function createAgency(Request $r) {return json(['data'=>(new Organizations())->save('agencies',$this->body($r))],201);}
    public function updateAgency(Request $r,string $code) {return json(['data'=>(new Organizations())->save('agencies',$this->body($r),$code)]);}
    public function grants(Request $r,string $code) { $b=$this->body($r); if(!isset($b['operators'])||!is_array($b['operators'])||!array_is_list($b['operators'])) throw new HttpException(422,'operators must be an array'); return json(['data'=>(new Organizations())->grants($code,$b['operators'])]); }
    public function rotateOperator(string $code) {return json(['data'=>(new Organizations())->rotateOperatorKey($code)]);}
    public function rotate(string $code) {return json(['data'=>(new Organizations())->rotateKey($code)]);}
    public function assign(Request $r,string $id) { $b=$this->body($r); if(!isset($b['operator_code'])||!is_string($b['operator_code'])||!ctype_digit($id)) throw new HttpException(422,'Invalid trip or operator'); (new Organizations())->assignTrip((int)$id,$b['operator_code']); return json(['data'=>['trip_id'=>$id,'operator_code'=>$b['operator_code']]]); }
}



