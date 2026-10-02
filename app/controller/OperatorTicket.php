<?php
namespace app\controller;
use think\Request;
use think\exception\HttpException;
use app\service\TicketTlv;
final class OperatorTicket
{
    private function body(Request $r): array {$b=json_decode($r->getContent(),true);if(!is_array($b)||array_is_list($b))throw new HttpException(422,'JSON object required');return $b;}
    public function index(Request $r){return json((new TicketTlv())->browse($r->operatorCode,$r->identity,(string)$r->get('date',''),(int)$r->get('page',1)));}
    public function history(Request $r,string $number,string $id){if(!ctype_digit($id)||(int)$id<1)throw new HttpException(422,'Invalid TLV ID');return json(['data'=>(new TicketTlv())->history($number,$r->operatorCode,(int)$id,$r->identity)]);}
    public function show(Request $r,string $number){return json(['data'=>(new TicketTlv())->get($number,$r->operatorCode,$r->identity)]);}
    public function entries(Request $r,string $number){return json((new TicketTlv())->listing($number,$r->operatorCode,$r->identity,(int)$r->get('page',1),$r->get('include_revoked')==='1'));}
    public function append(Request $r,string $number){return json(['data'=>(new TicketTlv())->append($number,$r->operatorCode,$this->body($r),$r->identity)],201);}
    public function revoke(Request $r,string $number,string $id){
        $body=$this->body($r);
        if(!ctype_digit($id)||(int)$id<1||!isset($body['reason'])||!is_string($body['reason']))throw new HttpException(422,'Valid TLV ID and reason required');
        return json(['data'=>(new TicketTlv())->revoke($number,$r->operatorCode,(int)$id,$body['reason'],$r->identity)]);
    }
    public function stream(Request $r,string $number){return json(['data'=>(new TicketTlv())->stream($number,$r->operatorCode,$r->identity)]);}
}

