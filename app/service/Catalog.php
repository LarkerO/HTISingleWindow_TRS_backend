<?php
declare(strict_types=1);
namespace app\service;
use think\facade\Db;
use app\mtr\WorldReader;
final class Catalog
{
    public static function sync(array $data,array $plan,string $dimension,string $source=''): void {
        Db::transaction(function()use($data,$plan,$dimension,$source){
            Db::table('mtr_depots')->where('dimension',$dimension)->update(['present'=>0]);
            $warnings=[];$counts=[];
            foreach($plan['warnings'] as $warning)$warnings[$warning['depot_id']]=$warning['reason'];
            foreach($plan['trips'] as $trip)$counts[$trip['depot_id']]=($counts[$trip['depot_id']]??0)+1;
            foreach($data['depots'] as $d){
                $id=(string)$d['id'];$count=$counts[$id]??0;
                $reason=$warnings[$id]??($d['route_ids']?'No departures':'No configured routes');
                $status=$count?'ready':($d['route_ids']?'blocked':'unconfigured');
                $row=['dimension'=>$dimension,'depot_id'=>$id,'name'=>$d['name'],'transport_mode'=>$d['transport_mode']??'TRAIN','route_ids'=>json_encode(array_map('strval',$d['route_ids'])),'departures'=>json_encode($d['departures']),'frequencies'=>json_encode($d['frequencies']),'use_real_time'=>(int)$d['use_real_time'],'repeat_infinitely'=>(int)$d['repeat_infinitely'],'present'=>1,'planning_status'=>$status,'planning_reason'=>$count?'':$reason,'planned_trip_count'=>$count,'source_path'=>$source,'updated_at'=>gmdate('Y-m-d H:i:s')];
                Db::table('mtr_depots')->duplicate($row)->insert($row);
                Db::table('mtr_depot_sidings')->where('dimension',$dimension)->where('depot_id',$id)->delete();
                foreach($data['sidings'] as $s){
                    if(!WorldReader::area($s,[$d]))continue;
                    Db::table('mtr_depot_sidings')->insert(['dimension'=>$dimension,'depot_id'=>$id,'siding_id'=>(string)$s['id'],'name'=>$s['name'],'train_type'=>$s['train_type']??'','path_segments'=>count($s['path']??[]),'is_manual'=>(int)($s['is_manual']??false),'max_trains'=>$s['max_trains']??0,'unlimited_trains'=>(int)($s['unlimited_trains']??false)]);
                }
            }
        });
    }
    public static function sources(string $root): array {
        if(!is_dir($root))throw new \InvalidArgumentException('MTR root directory required');
        $roots=[];$zips=[];$root=rtrim(realpath($root),DIRECTORY_SEPARATOR);
        $filter=new \RecursiveCallbackFilterIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),function($entry)use(&$roots,&$zips){
            if($entry->isDir()&&in_array($entry->getFilename(),['depots','stations','sidings','routes','rails','platforms','lifts','logs','signal-blocks'],true)){
                $roots[dirname($entry->getPathname())]=true;return false;
            }
            if($entry->isFile()&&str_ends_with($entry->getFilename(),'.zip'))$zips[]=$entry->getPathname();
            return $entry->isDir();
        });
        foreach(new \RecursiveIteratorIterator($filter,\RecursiveIteratorIterator::SELF_FIRST) as $unused){}
        $sources=[];
        foreach(array_keys($roots) as $path)$sources[str_replace(DIRECTORY_SEPARATOR,'/',substr($path,strlen($root)+1))]=$path;
        foreach($zips as $path){$dimension=str_replace(DIRECTORY_SEPARATOR,'/',substr($path,strlen($root)+1,-4));if(!isset($sources[$dimension]))$sources[$dimension]=$path;}
        ksort($sources);return $sources;
    }
}

