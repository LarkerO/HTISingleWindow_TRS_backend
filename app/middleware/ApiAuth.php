<?php
namespace app\middleware;
use think\facade\Db;
final class ApiAuth
{
    public function handle($request,\Closure $next) {
        try {
            $key=(string)$request->header('X-API-Key','');
            if($key==='') return json(['error'=>'Unauthorized'],401);
            $admin=(string)config('ticketing.api_key');
            if($admin!==''&&hash_equals($admin,$key)) {
                $request->identity=['role'=>'admin','agency_code'=>(string)$request->header('X-Agency-Code',config('ticketing.agency_code'))];
            } else {
                $agency=Db::table('agencies')->where('api_key_hash',hash('sha256',$key))->where('active',1)->find();
                if(!$agency) {
                    $operator=Db::table('operators')->where('api_key_hash',hash('sha256',$key))->where('active',1)->find();
                    if(!$operator)return json(['error'=>'Unauthorized'],401);
                    if($request->header('X-Agency-Code'))return json(['error'=>'Operator key cannot impersonate an agency'],403);
                    if($request->header('X-Operator-Code')&&$request->header('X-Operator-Code')!==$operator['code'])return json(['error'=>'Cannot impersonate another operator'],403);
                    $request->identity=['role'=>'operator','operator_code'=>$operator['code']];
                    return $next($request);
                }
                if($request->header('X-Agency-Code')&&$request->header('X-Agency-Code')!==$agency['code']) return json(['error'=>'Cannot impersonate another agency'],403);
                $request->identity=['role'=>'agency','agency_code'=>$agency['code']];
            }
            return $next($request);
        } catch(\think\exception\HttpException $e) { return json(['error'=>$e->getMessage()],$e->getStatusCode()); }
        catch(\Throwable $e) { \think\facade\Log::error($e->getMessage()); return json(['error'=>'Internal server error'],500); }
    }
}

