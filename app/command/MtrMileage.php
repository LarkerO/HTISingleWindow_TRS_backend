<?php
declare(strict_types=1);
namespace app\command;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Argument;
use think\console\input\Option;
use think\facade\Db;
/** Adds derived mileage only, including sold schedules; never changes times or inventory. */
final class MtrMileage extends Command
{
    protected function configure() {
        $this->setName('mtr:mileage')->addArgument('source',Argument::REQUIRED,'Original dimension directory or ZIP');
        foreach(['dimension','date'] as $key)$this->addOption($key,null,Option::VALUE_REQUIRED);
    }
    protected function execute(Input $in,Output $out) {
        $dimension=(string)$in->getOption('dimension');$date=(string)$in->getOption('date');
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if(!$dimension||!$parsed||$parsed->format('Y-m-d')!==$date)throw new \InvalidArgumentException('--dimension and valid --date required');
        $data=(new \app\mtr\WorldReader())->read((string)$in->getArgument('source'));
        $hash=hash('sha256',serialize($data));
        $plan=(new \app\mtr\Timetable())->build($data);$byKey=array_column($plan['trips'],null,'key');
        $updated=0;$skipped=0;
        Db::transaction(function()use($dimension,$date,$hash,$byKey,&$updated,&$skipped){
            $trips=Db::table('trips')->where('dimension',$dimension)->where('service_date',$date)->where('active',1)->order('id')->lock(true)->select();
            foreach($trips as $trip){
                $sourceHash=Db::table('imports')->where('id',$trip['import_id'])->value('source_hash');
                $planned=$byKey[$trip['trip_key']]??null;
                if($sourceHash!==$hash||!$planned){$skipped++;continue;}
                $stops=Db::table('stops')->where('trip_id',$trip['id'])->order('seq')->lock(true)->select()->toArray();
                if(count($stops)!==count($planned['stops'])){$skipped++;continue;}
                foreach($stops as $seq=>$stop){$p=$planned['stops'][$seq];if((int)$stop['seq']!==$seq||$stop['station_id']!==$dimension.':'.$p['station_id']||(string)$stop['platform_id']!==(string)$p['platform_id']){$skipped++;continue 2;}}
                foreach($stops as $seq=>$stop)Db::table('stops')->where('trip_id',$trip['id'])->where('seq',$seq)->update(\app\service\Mileage::relative($planned['stops'][$seq],$planned['stops'][0]));
                $updated++;
            }
        });
        $out->writeln(json_encode(['updated_trips'=>$updated,'skipped_source_or_path_mismatch'=>$skipped,'times_unchanged'=>true]));return 0;
    }
}
