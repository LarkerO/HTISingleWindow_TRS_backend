<?php
namespace app\middleware;
final class AgencyOrAdmin
{
    public function handle($request,\Closure $next) {
        if(!in_array($request->identity['role']??'',['admin','agency'],true))return json(['error'=>'Agency or administrator required'],403);
        return $next($request);
    }
}
