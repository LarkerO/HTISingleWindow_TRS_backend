<?php
namespace app\controller;
use think\Request;
use think\exception\HttpException;
use app\service\PassengerDocuments;
final class Passenger
{
    private function body(Request $r): array {
        $body=json_decode($r->getContent(),true);
        if(!is_array($body)||array_is_list($body))throw new HttpException(422,'JSON object required');
        return $body;
    }
    private function id(string $id): int {if(!ctype_digit($id)||(int)$id<1)throw new HttpException(422,'Invalid document ID');return (int)$id;}
    public function index(Request $r) {return json((new PassengerDocuments())->search($r->only(['document_type','document_number','issuing_country','name'],'get'),(int)$r->get('page',1)));}
    public function show(string $id) {return json(['data'=>(new PassengerDocuments())->get($this->id($id))]);}
    public function create(Request $r) {return json(['data'=>(new PassengerDocuments())->save($this->body($r),$r->identity)],201);}
    public function update(Request $r,string $id) {return json(['data'=>(new PassengerDocuments())->save($this->body($r),$r->identity,$this->id($id))]);}
    public function history(Request $r,string $id) {return json((new PassengerDocuments())->history($this->id($id),(int)$r->get('page',1)));}
}
