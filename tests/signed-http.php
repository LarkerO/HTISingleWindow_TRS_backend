<?php
function signedHeaders(string $key,string $method,string $path,?array $body=null,array $extra=[]): array {
    $headers=[];foreach($extra as $line){[$name,$value]=explode(':',$line,2);$headers[trim($name)]=trim($value);}
    $signed=\app\service\Sm3::headers($key,$method,$path,$body===null?'':json_encode($body),$headers);
    $out=[];foreach($signed as $name=>$value)$out[]=$name.': '.$value;return $out;
}
