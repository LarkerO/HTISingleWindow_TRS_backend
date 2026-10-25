<?php
declare(strict_types=1);
namespace app\service;
final class Mileage
{
    public static function relative(array $stop,array $first): array {
        if(!isset($stop['distance_m'],$first['distance_m'],$stop['speed_distances_m'],$first['speed_distances_m']))return ['distance_m'=>null,'speed_distances_m'=>null];
        $bands=[];foreach($stop['speed_distances_m'] as $speed=>$distance){$value=round($distance-($first['speed_distances_m'][$speed]??0),3);if($value>0)$bands[$speed]=$value;}
        ksort($bands,SORT_NUMERIC);
        return ['distance_m'=>round(array_sum($bands),3),'speed_distances_m'=>json_encode((object)$bands,JSON_THROW_ON_ERROR)];
    }
}
