<?php
declare(strict_types=1);
namespace app\command;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Argument;
use think\console\input\Option;
use think\facade\Db;
final class MtrImport extends Command
{
    protected function configure() {
        $this->setName('mtr:import')->addArgument('source',Argument::REQUIRED,'Single dimension directory or ZIP');
        foreach(['date','dimension','world-tick','mapping','operator','capacity','fare'] as $key) $this->addOption($key,null,Option::VALUE_REQUIRED);
        $this->addOption('dry-run',null,Option::VALUE_NONE);
        $this->addOption('merge',null,Option::VALUE_NONE,'Keep IDs for matching unsold trips');
    }
    protected function execute(Input $in,Output $out) {
        $date=(string)$in->getOption('date');
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,new \DateTimeZone('UTC'));
        if(!$parsed||$parsed->format('Y-m-d')!==$date) throw new \InvalidArgumentException('--date YYYY-MM-DD is required (UTC service date)');
        $dimension=(string)$in->getOption('dimension');
        if(!preg_match('~^[A-Za-z0-9_/-]{1,120}$~',$dimension)) throw new \InvalidArgumentException('--dimension e.g. minecraft/overworld is required');
        $source=(string)$in->getArgument('source');
        $data=(new \app\mtr\WorldReader())->read($source);
        $mapping=$in->getOption('mapping')?json_decode(file_get_contents($in->getOption('mapping')),true,512,JSON_THROW_ON_ERROR):[];
        $tick=$in->getOption('world-tick'); if($tick!==null&&!ctype_digit((string)$tick)) throw new \InvalidArgumentException('world-tick must be nonnegative integer');
        $plan=(new \app\mtr\Timetable())->build($data,$tick===null?null:(int)$tick,$mapping);
        $summary=$plan; $summary['trip_count']=count($plan['trips']); unset($summary['trips']);
        file_put_contents(app()->getRuntimePath().'import-report.json',json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        $out->writeln(json_encode($summary,JSON_UNESCAPED_UNICODE));
        if($in->getOption('dry-run')) return 0;
        if(!$plan['trips']) throw new \RuntimeException('No trips generated; existing timetable retained');
        $operator=(string)($in->getOption('operator')??'LOCAL');
        if(!preg_match('/^[A-Z0-9]{1,16}$/',$operator)) throw new \InvalidArgumentException('Invalid operator');
        $values=[];
        foreach(['capacity','fare'] as $k) { $v=(string)($in->getOption($k)??'0'); if(!ctype_digit($v)||(int)$v>1000000) throw new \InvalidArgumentException('Invalid '.$k); $values[$k]=(int)$v; }
        $id=(new \app\service\Importer())->import($data,$plan,$dimension,$date,hash('sha256',serialize($data)),$operator,$values['capacity'],$values['fare'],(bool)$in->getOption('merge'));
        $out->writeln('Import revision '.$id.' committed'); return 0;
    }
}

