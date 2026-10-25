<?php
declare(strict_types=1);
namespace app\middleware;
use app\service\Sm3;
use think\facade\Db;
use think\exception\HttpException;
final class ApiAuth
{
    public function handle($request,\Closure $next) {
        try {
            $id=(string)$request->header('X-API-Key-Id','');
            if(!preg_match('/^[a-f0-9]{64}$/D',$id))throw new HttpException(401,'SM3 signed authentication required');
            $admin=(string)config('ticketing.api_key');
            if($admin!==''&&hash_equals(Sm3::keyId(Sm3::secret($admin)),$id)){
                $secret=Sm3::secret($admin);$identity=['role'=>'admin','agency_code'=>(string)$request->header('X-Agency-Code',config('ticketing.agency_code'))];
            }else{
                $agency=Db::table('agencies')->where('api_key_id',$id)->where('active',1)->find();
                $operator=$agency?null:Db::table('operators')->where('api_key_id',$id)->where('active',1)->find();
                if(!$agency&&!$operator)throw new HttpException(401,'Unauthorized');
                $secret=($agency??$operator)['api_key_hash'];
                $identity=$agency?['role'=>'agency','agency_code'=>$agency['code']]:['role'=>'operator','operator_code'=>$operator['code']];
            }
            $timestamp=(string)$request->header('X-Timestamp','');$nonce=(string)$request->header('X-Nonce','');
            $signature=(string)$request->header('X-Signature','');
            if($request->header('X-Signature-Algorithm')!=='HMAC-SM3-V1'||!preg_match('/^[0-9]{10}$/D',$timestamp)||!preg_match('/^[a-f0-9]{32}$/D',$nonce)||!preg_match('/^[a-f0-9]{64}$/D',$signature))
                throw new HttpException(401,'Invalid SM3 signature headers');
            $now=time();$window=300;
            if(abs($now-(int)$timestamp)>$window)throw new HttpException(401,'Signature timestamp expired');
            $headers=[];foreach(['X-API-Key-Id','X-Timestamp','X-Nonce','Content-Type','X-Agency-Code','X-Operator-Code','Idempotency-Key'] as $name)$headers[$name]=(string)$request->header($name,'');
            $target=$request->server('REQUEST_URI');
            if(!hash_equals(Sm3::hmac(Sm3::request($request->method(),$target,$request->getContent(),$headers),$secret),$signature))
                throw new HttpException(401,'Invalid SM3 signature');
            // Atomic shared-store reservation works across PHP workers and hosts sharing this database.
            Db::table('request_nonces')->where('expires_at','<',$now)->delete();
            $inserted=Db::execute('INSERT IGNORE INTO request_nonces (key_id,nonce,expires_at) VALUES (?,?,?)',[$id,$nonce,max($now,(int)$timestamp)+$window+1]);
            if($inserted!==1)throw new HttpException(409,'Request nonce already used');
            $request->sm3Context=['secret'=>$secret,'signature'=>$signature,'nonce'=>$nonce];
            $request->identity=$identity;
            if($identity['role']==='operator'){
                if($request->header('X-Agency-Code'))throw new HttpException(403,'Operator key cannot impersonate an agency');
                if($request->header('X-Operator-Code')&&$request->header('X-Operator-Code')!==$identity['operator_code'])throw new HttpException(403,'Cannot impersonate another operator');
            }elseif($identity['role']==='agency'&&$request->header('X-Agency-Code')&&$request->header('X-Agency-Code')!==$identity['agency_code'])throw new HttpException(403,'Cannot impersonate another agency');
            return $next($request);
        }catch(HttpException $e){return json(['error'=>$e->getMessage()],$e->getStatusCode());}
        catch(\Throwable $e){\think\facade\Log::error($e->getMessage());return json(['error'=>'Internal server error'],500);}
    }
}
