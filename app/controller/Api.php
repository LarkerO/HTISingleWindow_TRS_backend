<?php
namespace app\controller;
use app\service\Ticketing;
use think\facade\Db;
use think\Request;
use think\exception\HttpException;
final class Api
{
    public function me(Request $r) {
        $identity=$r->identity;
        if($identity['role']==='operator') {
            $operator=Db::table('operators')->where('code',$identity['operator_code'])->field('code,name,active,whitelist_enabled')->find();
            return json(['data'=>$identity+['operator'=>$operator]]);
        }
        $agency=Db::table('agencies')->where('code',$identity['agency_code'])->field('code,name,issuer_code,active')->find();
        return json(['data'=>array_merge($identity,['agency'=>$agency])]);
    }
    private function tripQuery(Request $r) {
        $query=Db::table('trips')->alias('t')->join('operators o','o.code=t.operator_code')->where('t.active',1)->where('o.active',1);
        $date=(string)$r->get('date','');
        if($date!=='') {
            $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
            if(!$parsed||$parsed->format('Y-m-d')!==$date) throw new HttpException(422,'date must be YYYY-MM-DD');
            $query->where('t.service_date',$date);
        }
        $number=\app\service\TripFilters::number((string)$r->get('train_number',''));
        if($number!==null)$query->where('t.train_number',$number);
        [$from,$to]=\app\service\TripFilters::window($date,(string)$r->get('time_from',''),(string)$r->get('time_to',''));
        if($from!==null)$query->whereRaw('EXISTS (SELECT 1 FROM stops sf WHERE sf.trip_id=t.id AND sf.seq=0 AND sf.departure_at>=?)',[$from]);
        if($to!==null)$query->whereRaw('EXISTS (SELECT 1 FROM stops sf WHERE sf.trip_id=t.id AND sf.seq=0 AND sf.departure_at<?)',[$to]);
        if($r->get('operator')) $query->where('t.operator_code',(string)$r->get('operator'));
        if($r->get('dimension')) $query->where('t.dimension',(string)$r->get('dimension'));
        return $query;
    }
    public function depots(Request $r) {
        $stats=$this->tripQuery($r)->field('t.dimension,t.depot_id,COUNT(*) trip_count,COUNT(DISTINCT t.siding_id) scheduled_siding_count')->group('t.dimension,t.depot_id')->select()->toArray();
        $indexed=[];foreach($stats as $s)$indexed[$s['dimension'].':'.$s['depot_id']]=$s;
        $query=Db::table('mtr_depots')->alias('d')->leftJoin('operators owner','owner.code=d.operator_code')->where('d.present',1)->field('d.*,owner.name operator_name');
        if($r->get('dimension'))$query->where('d.dimension',(string)$r->get('dimension'));
        $rows=$query->order('d.name,d.dimension,d.depot_id')->select()->toArray();
        foreach($rows as &$d){
            $s=$indexed[$d['dimension'].':'.$d['depot_id']]??[];
            $d['trip_count']=(int)($s['trip_count']??0);
            $d['scheduled_siding_count']=(int)($s['scheduled_siding_count']??0);
            $d['sidings']=Db::table('mtr_depot_sidings')->where('dimension',$d['dimension'])->where('depot_id',$d['depot_id'])->order('siding_id')->select()->toArray();
            foreach($d['sidings'] as &$siding) {
                $siding['vehicle_limit']=$siding['unlimited_trains']?null:(int)$siding['max_trains']+1;
            }
            unset($siding);
            $d['siding_count']=count($d['sidings']);
            foreach(['route_ids','departures','frequencies'] as $field)$d[$field]=json_decode($d[$field],true);
            unset($d['source_path']);
        }
        return json(['data'=>$rows,'total'=>count($rows),'catalog_scope'=>'all_source_depots','trip_counts_scope'=>'requested_date_and_operator']);
    }
    public function trips(Request $r) {
        $page=max(1,min(100000,(int)$r->get('page',1)));
        $query=$this->tripQuery($r);
        $depot=(string)$r->get('depot_id','');
        if($depot!=='') {
            if(!preg_match('/^-?\d{1,19}$/',$depot)) throw new HttpException(422,'Invalid MTR depot_id');
            $query->where('t.depot_id',$depot);
        }
        $total=(clone $query)->count();
        $rows=$query->field('t.*,o.name operator_name')->order('t.service_date,t.depot_id,t.siding_id,t.run_number,t.id')->page($page,30)->select()->toArray();
        foreach($rows as &$t) {
            $t=\app\service\TripIdentity::present($t);
            $t['origin']=Db::table('stops')->where('trip_id',$t['id'])->order('seq')->find();
            $t['destination']=Db::table('stops')->where('trip_id',$t['id'])->order('seq','desc')->find();
        }
        return json(['data'=>$rows,'total'=>$total,'page'=>$page,'page_size'=>30,'timezone'=>'UTC']);
    }
    public function stations(Request $r) { $q=Db::table('stations')->order('name')->limit(500); if($r->get('q')) $q->whereLike('name','%'.mb_substr((string)$r->get('q'),0,100).'%'); if($r->get('dimension')) $q->where('dimension',$r->get('dimension')); return json(['data'=>$q->select(),'limit'=>500]); }
    public function journeys(Request $r) {
        $date=(string)$r->get('date'); $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if(!$d||$d->format('Y-m-d')!==$date) throw new HttpException(422,'date must be YYYY-MM-DD (UTC boarding date)');
        $a=(string)$r->get('origin'); $b=(string)$r->get('destination');
        if(!$a||!$b||$a===$b) throw new HttpException(422,'Distinct origin and destination station IDs required');
        return json(['data'=>(new Ticketing())->search($a,$b,$date,['train_number'=>(string)$r->get('train_number',''),'time_from'=>(string)$r->get('time_from',''),'time_to'=>(string)$r->get('time_to','')]),'timezone'=>'UTC','limit'=>200]);
    }
    public function trip(string $id) { $t=Db::table('trips')->where('id',$id)->find(); if(!$t) throw new HttpException(404,'Trip not found'); $t=\app\service\TripIdentity::present($t); $t['stops']=Db::table('stops')->where('trip_id',$id)->order('seq')->select(); return json(['data'=>$t,'timezone'=>'UTC']); }
    public function issue(Request $r) { $body=json_decode($r->getContent(),true); if(!is_array($body)) throw new HttpException(422,'JSON body required'); return json(['data'=>(new Ticketing())->issue($body,(string)$r->header('Idempotency-Key',''),(string)$r->identity['agency_code'])],201); }
    public function ticket(Request $r,string $number) { return json(['data'=>(new Ticketing())->get($number,(string)$r->identity['agency_code'])]); }
    public function refund(Request $r,string $number) { return json(['data'=>(new Ticketing())->refund($number,(string)$r->identity['agency_code'])]); }
}






