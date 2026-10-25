<?php
declare(strict_types=1);
/** CLI: TICKETING_API_KEY=... php examples/signed-request.php /api/v1/me */
require dirname(__DIR__).'/vendor/autoload.php';
use app\service\Sm3;
$key=(string)getenv('TICKETING_API_KEY');$base=rtrim((string)(getenv('API_BASE_URL')?:'http://localhost:8000'),'/');
$target=$argv[1]??'/api/v1/me';$method=strtoupper($argv[2]??'GET');
$body=isset($argv[3])?file_get_contents($argv[3]):'';
if($key===''||$body===false||!str_starts_with($target,'/api/v1/')||str_contains($target,'#'))throw new RuntimeException('API key, /api/v1/ target and readable optional JSON body file required');
$extra=$body!==''?['Content-Type'=>'application/json']:[];
foreach(['X-Agency-Code'=>'AGENCY_CODE','X-Operator-Code'=>'OPERATOR_CODE','Idempotency-Key'=>'IDEMPOTENCY_KEY'] as $header=>$variable){$value=getenv($variable);if($value!==false&&$value!=='')$extra[$header]=$value;}
$headers=Sm3::headers($key,$method,$target,$body,$extra);$lines=[];foreach($headers as $name=>$value)$lines[]=$name.': '.$value;
$reply=[];$curl=curl_init($base.$target);
curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>$lines,
    CURLOPT_HEADERFUNCTION=>function($c,$line)use(&$reply){if(str_contains($line,':')){[$name,$value]=explode(':',$line,2);$reply[strtolower(trim($name))]=trim($value);}return strlen($line);}]);
if($body!=='')curl_setopt($curl,CURLOPT_POSTFIELDS,$body);
$raw=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);$error=curl_error($curl);curl_close($curl);
if($raw===false)throw new RuntimeException('Transport error: '.$error);
$signature=$reply['x-response-signature']??'';$timestamp=$reply['x-response-timestamp']??'';
if(($reply['x-response-signature-algorithm']??'')!=='HMAC-SM3-V1'||!preg_match('/^[a-f0-9]{64}$/D',$signature)||!preg_match('/^[0-9]{10}$/D',$timestamp)||abs(time()-(int)$timestamp)>300)throw new RuntimeException('Unsigned or expired response; HTTP '.$status);
foreach(['x-request-id','x-process-time-ms','x-memory-delta-bytes','x-memory-peak-bytes'] as $name)if(!isset($reply[$name]))throw new RuntimeException('Missing signed response metadata');
$expected=Sm3::hmac(Sm3::response($headers['x-signature'],$headers['x-nonce'],$status,$reply,$raw),Sm3::secret($key));
if(!hash_equals($expected,$signature))throw new RuntimeException('Response SM3 signature failed');
echo $raw.PHP_EOL;exit($status>=400?1:0);
