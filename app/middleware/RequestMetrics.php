<?php
declare(strict_types=1);
namespace app\middleware;
use app\service\Sm3;
use think\facade\Log;
final class RequestMetrics
{
    public function handle($request,\Closure $next) {
        $start=hrtime(true);$memory=memory_get_usage();
        // PHP 8.2+: reset per-worker historical peak before measuring this request.
        if(function_exists('memory_reset_peak_usage'))memory_reset_peak_usage();
        try{$response=$next($request);}
        catch(\Throwable $e){$handler=app()->make(\think\exception\Handle::class);$handler->report($e);$response=$handler->render($request,$e);}
        $isApi=str_starts_with($request->pathinfo(),'api/')||$request->pathinfo()==='health';
        if(!$isApi)return $response;
        $content=$response->getContent(); // Include controller execution and first serialization.
        $duration=number_format((hrtime(true)-$start)/1000000,3,'.','');
        $metrics=['request_id'=>bin2hex(random_bytes(16)),'duration_ms'=>(float)$duration,
            'memory_delta_bytes'=>memory_get_usage()-$memory,'memory_peak_bytes'=>memory_get_peak_usage(),
            'memory_scope'=>function_exists('memory_reset_peak_usage')?'request':'worker'];
        if($response instanceof \think\response\Json){
            $data=json_decode($content,true,512,JSON_THROW_ON_ERROR);
            if(is_array($data)){$data['meta']['request']=$metrics;$response=json($data,$response->getCode(),$response->getHeader());$content=$response->getContent();}
        }
        $headers=['X-Request-Id'=>$metrics['request_id'],'X-Process-Time-Ms'=>$duration,
            'X-Memory-Delta-Bytes'=>(string)$metrics['memory_delta_bytes'],'X-Memory-Peak-Bytes'=>(string)$metrics['memory_peak_bytes'],
            'Server-Timing'=>'app;dur='.$duration,'Cache-Control'=>'no-store'];
        $context=$request->sm3Context??null;
        if($context){
            $headers['X-Response-Timestamp']=(string)time();$headers['X-Response-Signature-Algorithm']='HMAC-SM3-V1';
            $headers['X-Response-Signature']=Sm3::hmac(Sm3::response($context['signature'],$context['nonce'],$response->getCode(),$headers,$content),$context['secret']);
        }
        $response->header($headers);
        // No query values, bodies, identities, keys or signatures enter the performance log.
        try{Log::channel('requests')->info(json_encode($metrics+['method'=>$request->method(),'path'=>$request->pathinfo(),'status'=>$response->getCode()],JSON_UNESCAPED_SLASHES));}
        catch(\Throwable $e){error_log('Request metrics log could not be written');}
        return $response;
    }
}
