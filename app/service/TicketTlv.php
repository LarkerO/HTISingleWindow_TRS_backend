<?php
declare(strict_types=1);
namespace app\service;
use think\facade\Db;
use think\exception\HttpException;
final class TicketTlv
{
    private function ticket(string $number,string $operator,bool $lock=false): array {
        if(!preg_match('/^[0-9]{13}$/',$number))throw new HttpException(422,'Invalid ticket number');
        $ticket=Db::table('tickets')->where('ticket_number',$number)->find();
        if(!$ticket)throw new HttpException(404,'Ticket not found');
        $trips=Db::table('coupons')->alias('c')->join('trips t','t.id=c.trip_id')->where('c.ticket_id',$ticket['id'])->where('t.operator_code',$operator)->order('t.id')->column('t.id');
        if(!$trips)throw new HttpException(404,'Ticket not found for this operator');
        if($lock){
            Db::table('trips')->whereIn('id',$trips)->order('id')->lock(true)->select();
            $ticket=Db::table('tickets')->where('id',$ticket['id'])->lock(true)->find();
            if(!$ticket)throw new HttpException(404,'Ticket not found');
            // Ownership may have changed before the row lock was acquired.
            if(!Db::table('coupons')->alias('c')->join('trips t','t.id=c.trip_id')->where('c.ticket_id',$ticket['id'])->where('t.operator_code',$operator)->lock(true)->find())
                throw new HttpException(404,'Ticket not found for this operator');
        }
        return $ticket;
    }
    private function checkActor(array $identity,string $operator): array {
        if(($identity['role']??'')!=='admin' && (($identity['role']??'')!=='operator'||($identity['operator_code']??null)!==$operator))
            throw new HttpException(403,'Operator ticket management required');
        if(!Db::table('operators')->where('code',$operator)->find())throw new HttpException(404,'Operator not found');
        return PassengerDocuments::actor($identity);
    }
    public function get(string $number,string $operator,array $identity): array {
        $this->checkActor($identity,$operator);$ticket=$this->ticket($number,$operator);
        $ticket['passenger_document_snapshot']=$ticket['passenger_document_snapshot']?json_decode($ticket['passenger_document_snapshot'],true):null;
        $ticket['coupons']=Db::table('coupons')->alias('c')->join('trips t','t.id=c.trip_id')->where('c.ticket_id',$ticket['id'])->where('t.operator_code',$operator)->field('c.*')->select()->toArray();
        foreach($ticket['coupons'] as &$coupon){
            $coupon['trip']=TripIdentity::present(Db::table('trips')->where('id',$coupon['trip_id'])->find());
            $coupon['origin']=Db::table('stops')->where('trip_id',$coupon['trip_id'])->where('seq',$coupon['origin_seq'])->find();
            $coupon['destination']=Db::table('stops')->where('trip_id',$coupon['trip_id'])->where('seq',$coupon['destination_seq'])->find();
        }
        return $ticket;
    }
    public function listing(string $number,string $operator,array $identity,int $page=1,bool $includeRevoked=false): array {
        $this->checkActor($identity,$operator);$ticket=$this->ticket($number,$operator);$page=max(1,min(100000,$page));
        $query=Db::table('ticket_tlv')->where('ticket_id',$ticket['id'])->where('operator_code',$operator);
        if(!$includeRevoked)$query->whereNull('revoked_at');
        $total=(clone $query)->count();$rows=$query->order('id')->page($page,30)->select()->toArray();
        return ['data'=>array_map([TlvCodec::class,'present'],$rows),'total'=>$total,'page'=>$page,'page_size'=>30,'format'=>'project-tlv-v1'];
    }
    public function append(string $number,string $operator,array $body,array $identity): array {
        $actor=$this->checkActor($identity,$operator);$values=TlvCodec::input($body);
        return Db::transaction(function()use($number,$operator,$actor,$values){
            $ticket=$this->ticket($number,$operator,true);
            $now=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
            $id=(int)Db::table('ticket_tlv')->insertGetId($values+$actor+['ticket_id'=>$ticket['id'],'operator_code'=>$operator,'created_at'=>$now]);
            Db::table('ticket_tlv_events')->insert($actor+['tlv_id'=>$id,'action'=>'APPEND','reason'=>'Append TLV','occurred_at'=>$now]);
            return TlvCodec::present(Db::table('ticket_tlv')->where('id',$id)->find());
        });
    }
    public function revoke(string $number,string $operator,int $id,string $reason,array $identity): array {
        $actor=$this->checkActor($identity,$operator);$reason=trim($reason);
        if($reason===''||mb_strlen($reason)>500)throw new HttpException(422,'Revocation reason required (1–500 characters)');
        return Db::transaction(function()use($number,$operator,$id,$reason,$actor){
            $ticket=$this->ticket($number,$operator,true);
            $entry=Db::table('ticket_tlv')->where('id',$id)->where('ticket_id',$ticket['id'])->where('operator_code',$operator)->lock(true)->find();
            if(!$entry)throw new HttpException(404,'TLV entry not found');
            if($entry['revoked_at']===null){
                $now=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
                Db::table('ticket_tlv')->where('id',$id)->update(['revoked_at'=>$now]);
                Db::table('ticket_tlv_events')->insert($actor+['tlv_id'=>$id,'action'=>'REVOKE','reason'=>$reason,'occurred_at'=>$now]);
                $entry['revoked_at']=$now;
            }
            return TlvCodec::present($entry);
        });
    }
    public function browse(string $operator,array $identity,?string $date=null,int $page=1): array {
        $this->checkActor($identity,$operator);$bindings=[$operator];
        $where='tr.operator_code=?';
        if($date!==null&&$date!=='') {
            $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,new \DateTimeZone('UTC'));
            if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new HttpException(422,'date must be YYYY-MM-DD');
            $where.=' AND tr.service_date=?';$bindings[]=$date;
        }
        $page=max(1,min(100000,$page));
        $from=' FROM tickets tk JOIN coupons c ON c.ticket_id=tk.id JOIN trips tr ON tr.id=c.trip_id WHERE '.$where;
        $total=(int)Db::query('SELECT COUNT(DISTINCT tk.id) total'.$from,$bindings)[0]['total'];
        $rows=Db::query('SELECT DISTINCT tk.id,tk.ticket_number,tk.passenger,tk.status,tk.issued_at,tk.agency_code'.$from.' ORDER BY tk.id DESC LIMIT 30 OFFSET '.(($page-1)*30),$bindings);
        return ['data'=>$rows,'total'=>$total,'page'=>$page,'page_size'=>30];
    }
    public function history(string $number,string $operator,int $id,array $identity): array {
        $this->checkActor($identity,$operator);$ticket=$this->ticket($number,$operator);
        if(!Db::table('ticket_tlv')->where('id',$id)->where('ticket_id',$ticket['id'])->where('operator_code',$operator)->find())throw new HttpException(404,'TLV entry not found');
        return Db::table('ticket_tlv_events')->where('tlv_id',$id)->order('id')->select()->toArray();
    }
    public function stream(string $number,string $operator,array $identity): array {
        $this->checkActor($identity,$operator);$ticket=$this->ticket($number,$operator);$wire='';$count=0;
        foreach(Db::table('ticket_tlv')->where('ticket_id',$ticket['id'])->where('operator_code',$operator)->whereNull('revoked_at')->order('id')->cursor() as $row){
            $wire.=TlvCodec::wire($row);$count++;
            if(strlen($wire)>1048576)throw new HttpException(422,'Stream exceeds 1 MiB; use paginated TLV listing');
        }
        return ['format'=>'project-tlv-v1','byte_order'=>'big-endian','tag_bytes'=>2,'length_bytes'=>4,'record_count'=>$count,'length'=>strlen($wire),'wire_base64'=>base64_encode($wire)];
    }
}

