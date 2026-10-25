<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=new think\App(dirname(__DIR__));$app->initialize();
set_exception_handler(function(Throwable $e):void{fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
use app\service\Sm3;
function checkSignature(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
checkSignature(Sm3::digest('abc')==='66c7f0f462eeedd9d1f2d46bdc10e4e24167c4875cf2f7a2297da02b8f4ba8e0','SM3 standard vector');
$port=18769;$pipes=[];
$proc=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',dirname(__DIR__).'/public',dirname(__DIR__).'/public/router.php'],[0=>['pipe','r'],1=>['file',dirname(__DIR__).'/runtime/sm3-test.log','a'],2=>['file',dirname(__DIR__).'/runtime/sm3-test.log','a']],$pipes,dirname(__DIR__));
function signedCall(string $path,string $method='GET',string $body='',?array $headers=null): array {
 global $port;$key=(string)config('ticketing.api_key');$headers??=Sm3::headers($key,$method,$path,$body,$body!==''?['Content-Type'=>'application/json']:[]);
 $curl=curl_init('http://127.0.0.1:'.$port.$path);$out=[];foreach($headers as $name=>$value)$out[]=$name.': '.$value;
 $responseHeaders=[];curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$out,CURLOPT_HEADERFUNCTION=>function($c,$line)use(&$responseHeaders){if(str_contains($line,':')){[$name,$value]=explode(':',$line,2);$responseHeaders[strtolower(trim($name))]=trim($value);}return strlen($line);}]);
 if($body!=='')curl_setopt($curl,CURLOPT_POSTFIELDS,$body);
 $raw=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
 return [$status,json_decode($raw?:'',true),$responseHeaders,$raw,$headers];
}
try{
 for($i=0;$i<30;$i++){usleep(100000);if(signedCall('/health')[0]===200)break;}
 $key=(string)config('ticketing.api_key');$path='/api/v1/stations?q=%E4%B8%AD&a=1&a=2';
 [$status,$data,$reply,$raw,$sent]=signedCall($path);
 checkSignature($status===200,'Signed request succeeds');
 checkSignature(isset($data['meta']['request']['duration_ms'],$data['meta']['request']['memory_peak_bytes'])&&isset($reply['server-timing']),'Metrics in JSON and headers');
 checkSignature(hash_equals(Sm3::hmac(Sm3::response($sent['x-signature'],$sent['x-nonce'],$status,$reply,$raw),Sm3::secret($key)),$reply['x-response-signature']??''),'Response body and metrics signed');
 checkSignature(signedCall($path,'GET','',$sent)[0]===409,'Replay rejected');
 checkSignature(signedCall($path,'GET','',['X-API-Key'=>$key])[0]===401,'Unsigned legacy key rejected');
 $headers=Sm3::headers($key,'GET',$path);$headers['x-signature']=str_repeat('0',64);
 checkSignature(signedCall($path,'GET','',$headers)[0]===401,'Wrong signature rejected');
 $headers=Sm3::headers($key,'GET',$path);$headers['x-timestamp']=(string)(time()-301);$headers['x-signature']=Sm3::hmac(Sm3::request('GET',$path,'',$headers),Sm3::secret($key));
 checkSignature(signedCall($path,'GET','',$headers)[0]===401,'Expired signature rejected');
 $headers=Sm3::headers($key,'GET',$path);
 checkSignature(signedCall($path.'&tampered=1','GET','',$headers)[0]===401,'Query tampering rejected');
 $headers=Sm3::headers($key,'GET',$path);$headers['x-agency-code']='OTHER';
 checkSignature(signedCall($path,'GET','',$headers)[0]===401,'Agency context tampering rejected');
 $headers=Sm3::headers($key,'GET',$path);$headers['x-operator-code']='OTHER';
 checkSignature(signedCall($path,'GET','',$headers)[0]===401,'Operator context tampering rejected');
 $headers=Sm3::headers($key,'POST','/api/v1/admin/clear-date','{}',['Content-Type'=>'application/json','Idempotency-Key'=>'original']);
 checkSignature(signedCall('/api/v1/admin/clear-date','POST','{"tampered":true}',$headers)[0]===401,'Body tampering rejected');
 $headers['idempotency-key']='changed';
 checkSignature(signedCall('/api/v1/admin/clear-date','POST','{}',$headers)[0]===401,'Idempotency key tampering rejected');
 $error=signedCall('/api/v1/journeys?date=bad');
 checkSignature($error[0]===422&&isset($error[1]['meta']['request'],$error[2]['x-response-signature']),'Authenticated errors signed with metrics');
 $bad=signedCall('/api/v1/stations','GET','',[]);
 checkSignature($bad[0]===401&&isset($bad[1]['meta']['request']),'Authentication failure still has metrics');
 checkSignature((bool)glob(dirname(__DIR__).'/runtime/requests/*.log'),'Performance logs persisted');
}finally{proc_terminate($proc);foreach($pipes as $pipe)fclose($pipe);proc_close($proc);}
