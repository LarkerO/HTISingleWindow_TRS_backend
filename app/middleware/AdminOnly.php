<?php
namespace app\middleware;
final class AdminOnly
{
    public function handle($request,\Closure $next) {
        if(($request->identity['role']??'')!=='admin') return json(['error'=>'Administrator access required'],403);
        return $next($request);
    }
}
