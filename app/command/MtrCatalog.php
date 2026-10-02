<?php
namespace app\command;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Argument;
use think\console\input\Option;
use app\service\Catalog;
use app\mtr\WorldReader;
use app\mtr\Timetable;
final class MtrCatalog extends Command
{
    protected function configure(){ $this->setName('mtr:catalog')->addArgument('source',Argument::REQUIRED,'Entire world/mtr root or single dimension')->addOption('dimension',null,Option::VALUE_REQUIRED)->addOption('world-tick',null,Option::VALUE_REQUIRED); }
    protected function execute(Input $in,Output $out){
        $dimension=$in->getOption('dimension');$source=(string)$in->getArgument('source');
        $sources=$dimension?[$dimension=>$source]:Catalog::sources($source);$summary=[];
        foreach($sources as $dimension=>$path){
            if(!preg_match('~^[A-Za-z0-9_/-]{1,120}$~',$dimension))throw new \InvalidArgumentException('Invalid dimension');
            $data=(new WorldReader())->read($path,false);$tick=$in->getOption('world-tick');
            if($tick!==null&&!ctype_digit((string)$tick))throw new \InvalidArgumentException('Invalid world-tick');
            $plan=(new Timetable())->build($data,$tick===null?null:(int)$tick);
            Catalog::sync($data,$plan,$dimension,$path);
            $summary[$dimension]=['source'=>$path,'counts'=>$plan['counts'],'planned_trips'=>count($plan['trips']),'warnings'=>$plan['warnings']];
            $out->writeln($dimension.': '.count($data['depots']).' depots, '.count($data['sidings']).' sidings; '.count($plan['trips']).' planned trips');
        }
        file_put_contents(app()->getRuntimePath().'catalog-report.json',json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        $out->writeln('Full catalog updated; existing trips and tickets retained.');return 0;
    }
}
