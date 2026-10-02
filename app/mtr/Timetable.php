<?php
declare(strict_types=1);
namespace app\mtr;
/** Replays Siding.generateTimeSegments at 20 ticks/s; planned, not live times. */
final class Timetable
{
    private const SPEEDS=['WOODEN'=>20,'STONE'=>40,'EMERALD'=>60,'IRON'=>80,'OBSIDIAN'=>120,'BLAZE'=>160,'QUARTZ'=>200,'DIAMOND'=>300,'CABLE_CAR'=>30,'CABLE_CAR_STATION'=>2,'RUNWAY'=>300,'AIRPLANE_DUMMY'=>900];
    private static function f(float $v): float { return unpack('f',pack('f',$v))[1]; }
    public function stops(array $s,array $platforms,array $stations): array {
        if(($s['transport_mode']??'TRAIN')!=='TRAIN'||($s['is_manual']??false)) throw new \RuntimeException('Unsupported manual/non-train siding');
        if(($s['repeat_index_1']??0)||($s['repeat_index_2']??0)) throw new \RuntimeException('Infinite repeating path needs explicit cycle policy');
        $path=$s['path']??[]; if(count($path)<2) throw new \RuntimeException('No generated path');
        $lengths=[]; $targets=[]; $total=0;
        foreach($path as $p) { $r=$p['rail']; $len=abs($r['t_end_1']-$r['t_start_1'])+abs($r['t_end_2']-$r['t_start_2']); $lengths[]=$len; $total+=$len; if($p['dwell_time']>0) $targets[]=$total; }
        preg_match('/(?:train|base)_(\d+)_/', $s['train_type']??'', $m);
        $spacing=($m[1]??1)+1; $railLength=round($s['rail_length'],3); $cars=(int)floor($railLength/$spacing);
        if($cars<1) throw new \RuntimeException('No valid train cars');
        $progress=($railLength+$cars*$spacing)/2; $next=0; $sum=0; $speed=0.; $time=0; $stops=[];
        $a=self::f(max(0.001,round($s['acceleration_constant']??0.01,3)));
        foreach($path as $i=>$p) {
            if($progress>=$next) $next=array_shift($targets)??$total;
            $type=$p['rail']['rail_type']; if(!isset(self::SPEEDS[$type])&&!in_array($type,['PLATFORM','SIDING','TURN_BACK','NONE'],true)) throw new \RuntimeException('Unknown rail type: '.$type); $limit=isset(self::SPEEDS[$type])?self::f(self::f(self::SPEEDS[$type]/self::f(3.6))/20):max($speed,self::f(self::f(20/self::f(3.6))/20));
            $sum+=$lengths[$i];
            while($progress<$sum) {
                if($speed>$limit || $next-$progress+1<0.5*$speed*$speed/$a) $speed=max(self::f($speed-$a),$a);
                elseif($speed<$limit) $speed=min(self::f($speed+$a),$limit);
                $progress=min($progress+$speed,$sum); $time++;
                if($time>1728000) throw new \RuntimeException('Path exceeds one day simulation limit');
            }
            $pid=(string)$p['saved_rail_base_id'];
            if(isset($platforms[$pid])&&$p['dwell_time']>0) {
                $station=WorldReader::area($platforms[$pid],$stations);
                if(!$station) throw new \RuntimeException('Platform without station: '.$pid);
                $stops[]=['station_id'=>(string)$station['id'],'platform_id'=>$pid,'arrival_offset_ms'=>$time*50,'departure_offset_ms'=>($time+$p['dwell_time']*10)*50,'stop_index'=>$p['stop_index']];
            }
            $time+=$p['dwell_time']*10;
            $q=$path[$i+1]??null;
            if($q && $p['starting_pos']===$q['ending_pos']&&$p['ending_pos']===$q['starting_pos']) $progress+=$spacing*$cars;
        }
        if(count($stops)<2) throw new \RuntimeException('Fewer than two passenger stops');
        return $stops;
    }
    public function build(array $data,?int $worldTick=null,array $selection=[]): array {
        $trips=[]; $warnings=[];
        foreach($data['depots'] as $d) {
            $id=(string)$d['id'];
            if(!$d['route_ids']) continue;
            try {
                if($d['repeat_infinitely']) throw new \RuntimeException('Infinite depot requires cycle policy');
                $layout=RoutePlan::layout($d,$data);
                $candidates=[];
                foreach($data['sidings'] as $s) {
                    // max_trains is zero-based: 0 means one train (SidingScreen +1).
                    // Siding.simulateTrain also spawns when trains.isEmpty().
                    $area=WorldReader::area($s,[$d]);
                    if($area && (string)$area['id']===$id) $candidates[]=$s;
                }
                if(isset($selection[$id])) $candidates=array_values(array_filter($candidates,fn($s)=>(string)$s['id']===(string)$selection[$id]));
                if(!$candidates) throw new \RuntimeException('No siding in depot or selected mapping');
                // MTR sorts sidings by name/color and rotates through ready trains.
                // Offline planning assumes valid sidings ready; actual readiness is dynamic.
                usort($candidates,static function($a,$b){
                    return strcmp(mb_strtolower($a['name']).($a['color']??0),mb_strtolower($b['name']).($b['color']??0)) ?: strcmp((string)$a['id'],(string)$b['id']);
                });
                $usable=[];
                foreach($candidates as $s){
                    try { $calls=$this->stops($s,$data['platforms'],$data['stations']); $usable[]=['siding'=>$s,'legs'=>RoutePlan::split($s,$calls,$layout)]; }
                    catch(\RuntimeException $e){ $warnings[]=['depot_id'=>$id,'name'=>$d['name'],'reason'=>'Siding '.$s['id'].': '.$e->getMessage()]; }
                }
                if(!$usable) continue;
                if($d['use_real_time']) { $departures=array_values(array_unique(array_map(fn($n)=>(($n%86400000)+86400000)%86400000,$d['departures']))); sort($departures); }
                else {
                    if($worldTick===null) throw new \RuntimeException('Frequency timetable requires --world-tick at UTC midnight');
                    $departures=[];
                    for($ms=0;$ms<86400000;) {
                        $tick=(($worldTick+$ms/50+6000)%24000+24000)%24000; $hour=(int)floor($tick/1000); $f=$d['frequencies'][$hour]??0;
                        if($f>0) { $departures[]=(int)$ms; $ms+=intdiv(200000,$f); }
                        else $ms+=(1000-($tick%1000))*50;
                        if(count($departures)>50000) throw new \RuntimeException('Too many departures');
                    }
                }
                $runs=[];
                foreach($departures as $runIndex=>$departure){
                    $assigned=$usable[$runIndex%count($usable)]; $s=$assigned['siding']; $sid=(string)$s['id'];
                    // Each directional route is a separate passenger trip; retain full-path time offsets.
                    foreach($assigned['legs'] as $leg) {
                        $runs[$sid]=($runs[$sid]??0)+1;
                        $trips[]=['key'=>hash('sha256',$id.':'.$sid.':'.$departure.':route-leg:'.$leg['route_leg']),'depot_id'=>$id,'siding_id'=>$sid,'physical_key'=>hash('sha256',$id.':'.$sid.':'.$departure),'departure_ms'=>$departure,'run_number'=>$runs[$sid],'name'=>$leg['name'],'train_number'=>TrainNumber::extract($leg['name']),'route_ids'=>[$leg['route_id']],'stops'=>$leg['stops']];
                    }
                }
                if(!$departures) $warnings[]=['depot_id'=>$id,'name'=>$d['name'],'reason'=>'No departures'];
            } catch(\RuntimeException $e) { $warnings[]=['depot_id'=>$id,'name'=>$d['name'],'reason'=>$e->getMessage()]; }
        }
        return ['counts'=>array_map('count',$data),'trips'=>$trips,'warnings'=>$warnings,'clock'=>'UTC; MTR System.currentTimeMillis modulo 86400000','method'=>'MTR 3.x tick simulation; offline planned siding, no live availability'];
    }
}







