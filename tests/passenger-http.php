<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';$app=new think\App(dirname(__DIR__));$app->initialize();
$suffix=strtoupper(bin2hex(random_bytes(5)));$port=18766;$server=null;$fixture=null;
function fixture(string $mode):array{
 global $suffix;$pipes=[];$proc=proc_open([PHP_BINARY,__DIR__.'/passenger-fixture.php',$mode,$suffix],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
 fclose($pipes[0]);$raw=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($proc);
 if($status!==0)throw new RuntimeException('Fixture failed: '.$error);
 $result=json_decode($raw,true);if(!is_array($result))throw new RuntimeException('Invalid fixture result');return $result;
}
function call(string $path,string $key,string $method='GET',?array $body=null,array $extra=[]):array{
 global $port;$curl=curl_init('http://127.0.0.1:'.$port.$path);
 curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>array_merge(['X-API-Key: '.$key,'Content-Type: application/json'],$extra)]);
 if($body!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($body));
 $raw=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);return [$status,json_decode($raw?:'',true)];
}
function expect(int $status,array $result,string $label):array{if($result[0]!==$status)throw new RuntimeException($label.' HTTP '.$result[0]);echo 'PASS '.$label.PHP_EOL;return $result[1]??[];}
try{
 $fixture=fixture('setup');$pipes=[];$server=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',dirname(__DIR__).'/public',dirname(__DIR__).'/public/router.php'],[0=>['pipe','r'],1=>['file',dirname(__DIR__).'/runtime/passenger-http.log','a'],2=>['file',dirname(__DIR__).'/runtime/passenger-http.log','a']],$pipes,dirname(__DIR__));fclose($pipes[0]);
 $admin=(string)config('ticketing.api_key');$agency=$fixture['agency_key'];$operator=$fixture['operator_key'];
 for($i=0;$i<30;$i++){usleep(100000);if(call('/health',$admin)[0]===200)break;}
 expect(200,call('/api/v1/me',$operator),'Operator key authentication');
 expect(403,call('/api/v1/passenger-documents',$operator),'Operator cannot enumerate global identity library');
 expect(403,call('/api/v1/tickets',$operator,'POST',[]),'Operator key cannot issue as an agency');
 expect(403,call('/api/v1/me',$operator,'GET',null,['X-Operator-Code: LOCAL']),'Operator cannot impersonate another operator');
 $doc=$fixture['document'];$result=expect(200,call('/api/v1/passenger-documents?document_number='.$doc['document_number'],$agency),'Agency exact document lookup');
 if(count($result['data'])!==1)throw new RuntimeException('Document lookup mismatch');
 $extra=['document_type'=>'PASSPORT','document_number'=>$doc['document_number'].'-EXTRA','issuing_country'=>'CHN','birth_date'=>'1985-01-02','given_name'=>'HTTP 新旅客','reason'=>'HTTP registration'];
 expect(201,call('/api/v1/passenger-documents',$agency,'POST',$extra),'Agency creates global passenger document');
 expect(409,call('/api/v1/passenger-documents',$agency,'POST',$extra),'Duplicate document identity rejected');

 $body=array_intersect_key($doc,array_flip(['document_type','document_number','issuing_country','birth_date','surname','given_name']));$body['given_name']='HTTP 更新名';$body['version']=1;$body['reason']='HTTP correction';
 $changed=expect(200,call('/api/v1/passenger-documents/'.$doc['id'],$agency,'PUT',$body),'Agency update with revision and audit actor')['data'];
 expect(409,call('/api/v1/passenger-documents/'.$doc['id'],$agency,'PUT',$body),'Stale document revision rejected');
 $history=expect(200,call('/api/v1/passenger-documents/'.$doc['id'].'/history',$admin),'Document modification history');
 if($history['total']!==2||$history['data'][0]['actor_code']!==$fixture['agency'])throw new RuntimeException('Audit actor mismatch');
 $ticket=expect(201,call('/api/v1/tickets',$agency,'POST',['trip_id'=>$fixture['trip_id'],'origin_seq'=>0,'destination_seq'=>1,'document_id'=>$doc['id']],['Idempotency-Key: '.$suffix]),'Document-based ticket issue')['data'];
 $number=$ticket['ticket_number'];$path='/api/v1/operator/tickets/'.$number;
 $body['version']=2;$body['given_name']='HTTP 更晚更新';expect(200,call('/api/v1/passenger-documents/'.$doc['id'],$agency,'PUT',$body),'Post-issue global document correction');
 $owned=expect(200,call($path,$operator),'Operator reads own ticket snapshot')['data'];
 if($owned['passenger_document_snapshot']['given_name']!=='HTTP 更新名')throw new RuntimeException('Frozen ticket snapshot changed');
 expect(404,call($path,$admin,'GET',null,['X-Operator-Code: LOCAL']),'Other operator cannot read this ticket');
 expect(403,call($path.'/tlv',$agency,'POST',['tag'=>1,'value'=>'bad']),'Agency cannot add operator TLV');
 expect(422,call($path.'/tlv',$operator,'POST',['tag'=>1,'value'=>'中文','length'=>2]),'TLV incorrect byte length rejected');
 $entry=expect(201,call($path.'/tlv',$operator,'POST',['tag'=>12,'value'=>'中文','length'=>6]),'Operator appends UTF-8 TLV')['data'];
 expect(201,call($path.'/tlv',$operator,'POST',['tag'=>12,'encoding'=>'base64','value'=>'AP+A','length'=>3]),'Operator appends repeated tag and binary bytes');
 $stream=expect(200,call($path.'/tlv-stream',$operator),'Export operator TLV stream')['data'];if($stream['record_count']!==2||$stream['length']!==21)throw new RuntimeException('Wire stream size mismatch');
 expect(200,call($path.'/tlv/'.$entry['id'],$operator,'DELETE',['reason'=>'Correct invalid note']),'Operator revokes TLV with reason');
 expect(200,call($path.'/tlv/'.$entry['id'].'/history',$operator),'Read operator TLV audit');
 expect(422,call($path,$admin),'Admin operator management requires explicit context');
 expect(200,call($path,$admin,'GET',null,['X-Operator-Code: '.$fixture['operator']]),'Admin explicit operator context');
 $rotation=expect(200,call('/api/v1/admin/operators/'.$fixture['operator'].'/key',$admin,'POST',[]),'Rotate operator key')['data'];
 expect(401,call('/api/v1/me',$operator),'Old operator key invalidated');
 expect(200,call('/api/v1/me',$rotation['api_key']),'New operator key valid');
}finally{
 if(is_resource($server)){proc_terminate($server);proc_close($server);}
 fixture('cleanup');
}
echo 'Passenger/TLV HTTP fixtures cleaned.'.PHP_EOL;

