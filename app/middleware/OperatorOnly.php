<?php
namespace app\middleware;
use think\facade\Db;
final class OperatorOnly
{
    public function handle($request,\Closure $next) {
        $identity=$request->identity;
        if(($identity['role']??'')==='operator'){$request->operatorCode=$identity['operator_code'];return $next($request);}
        if(($identity['role']??'')!=='admin')return json(['error'=>'Operator or administrator required'],403);
        $code=(string)$request->header('X-Operator-Code','');
        if($code===''||!Db::table('operators')->where('code',$code)->find())return json(['error'=>'Valid X-Operator-Code is required'],422);
        $request->operatorCode=$code;return $next($request);
    }
}
