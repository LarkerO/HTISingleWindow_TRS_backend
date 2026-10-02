<?php
declare(strict_types=1);
namespace app\service;
use think\exception\HttpException;
/** Project TLV v1: unsigned 16-bit tag + unsigned 32-bit byte length, big-endian. */
final class TlvCodec
{
    public const MAX_VALUE_BYTES=65536;
    public static function input(array $body): array {
        if(!isset($body['tag'])||!is_int($body['tag'])||$body['tag']<0||$body['tag']>65535)throw new HttpException(422,'tag must be a JSON integer from 0 to 65535');
        $encoding=$body['encoding']??'utf8';$value=$body['value']??null;
        if(!is_string($encoding)||!is_string($value)||!in_array($encoding,['utf8','hex','base64'],true))throw new HttpException(422,'value must be a string with utf8, hex or base64 encoding');
        if(strlen($value)>self::MAX_VALUE_BYTES*2)throw new HttpException(422,'TLV value is too large');
        if($encoding==='utf8') {
            if(!mb_check_encoding($value,'UTF-8'))throw new HttpException(422,'Invalid UTF-8 value');
            $bytes=$value;
        } elseif($encoding==='hex') {
            if(strlen($value)%2!==0 || ($value!==''&&!ctype_xdigit($value)))throw new HttpException(422,'Invalid hex value');
            $bytes=hex2bin($value);
        } else {
            $bytes=base64_decode($value,true);
            if($bytes===false||base64_encode($bytes)!==$value)throw new HttpException(422,'Invalid canonical base64 value');
        }
        $length=strlen($bytes);
        if($length>self::MAX_VALUE_BYTES)throw new HttpException(422,'TLV value exceeds 65536 bytes');
        if(array_key_exists('length',$body)&&(!is_int($body['length'])||$body['length']!==$length))throw new HttpException(422,'length must match encoded value byte length');
        $label=$body['label']??'';
        if(!is_string($label)||mb_strlen($label)>160)throw new HttpException(422,'Invalid TLV label');
        return ['tag'=>$body['tag'],'byte_length'=>$length,'encoding'=>$encoding,'value_base64'=>base64_encode($bytes),'label'=>trim($label)];
    }
    public static function wire(array $row): string {
        $bytes=base64_decode($row['value_base64'],true);
        if($bytes===false||strlen($bytes)!==(int)$row['byte_length'])throw new \RuntimeException('Stored TLV length mismatch');
        return pack('nN',(int)$row['tag'],strlen($bytes)).$bytes;
    }
    public static function present(array $row): array {
        $bytes=base64_decode($row['value_base64'],true);
        $row['id']=(int)$row['id'];$row['tag']=(int)$row['tag'];$row['length']=(int)$row['byte_length'];
        $row['value']=match($row['encoding']){'utf8'=>$bytes,'hex'=>strtoupper(bin2hex($bytes)),default=>$row['value_base64']};
        $row['wire_base64']=base64_encode(self::wire($row));$row['active']=$row['revoked_at']===null;
        unset($row['byte_length'],$row['value_base64']);return $row;
    }
}
