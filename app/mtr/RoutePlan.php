<?php
declare(strict_types=1);
namespace app\mtr;

/** Depot.generateMainRoute concatenates route platform IDs, removing adjacent IDs only. */
final class RoutePlan
{
    public static function layout(array $depot,array $data): array {
        $platforms=[]; $legs=[];
        foreach($depot['route_ids'] as $routeId) {
            $route=$data['routes'][(string)$routeId]??null;
            if(!$route) throw new \RuntimeException('Configured route missing: '.$routeId);
            $indices=[];
            foreach($route['platform_ids'] as $pid) {
                $pid=(string)$pid;
                if(!isset($data['platforms'][$pid])) throw new \RuntimeException('Configured platform missing: '.$pid);
                if(!$platforms || end($platforms)!==$pid) $platforms[]=$pid;
                $index=count($platforms); // PathFinder: first passenger platform has stopIndex=1.
                if(!$indices || end($indices)!==$index) $indices[]=$index;
            }
            $legs[]=['route_id'=>(string)$routeId,'name'=>$route['name'],'indices'=>$indices];
        }
        return ['platforms'=>$platforms,'legs'=>$legs];
    }

    public static function split(array $siding,array $stops,array $layout): array {
        $expected=$layout['platforms']; $seen=[]; $last=0;
        // Validate saved path against current routes, including zero-dwell pass-throughs.
        foreach($siding['path'] as $p) {
            $index=(int)$p['stop_index'];
            $pid=(string)$p['saved_rail_base_id'];
            if($pid==='0' || $pid===(string)$siding['id']) continue;
            if($index<1 || !isset($expected[$index-1]) || $expected[$index-1]!==$pid || $index<$last)
                throw new \RuntimeException('Saved path does not match configured route at stop index '.$index.'; regenerate path in MTR');
            $seen[$index]=true; $last=$index;
        }
        if(count($seen)!==count($expected)) throw new \RuntimeException('Saved path is missing configured platforms; regenerate path in MTR');
        $byIndex=[];
        foreach($stops as $stop) {
            $index=(int)$stop['stop_index'];
            if(isset($byIndex[$index])) {
                // Opposite traversal of the same platform is one call, not another station.
                $byIndex[$index]['departure_offset_ms']=$stop['departure_offset_ms'];
            } else $byIndex[$index]=$stop;
        }
        $result=[];
        foreach($layout['legs'] as $legIndex=>$leg) {
            $calls=[];
            foreach($leg['indices'] as $index) if(isset($byIndex[$index])) $calls[]=$byIndex[$index];
            if(count($calls)<2 || count(array_unique(array_column($calls,'station_id')))<2) continue;
            $result[]=['route_id'=>$leg['route_id'],'name'=>$leg['name'],'route_leg'=>$legIndex+1,'stops'=>$calls];
        }
        if(!$result) throw new \RuntimeException('No configured route with two passenger stations');
        return $result;
    }
}
