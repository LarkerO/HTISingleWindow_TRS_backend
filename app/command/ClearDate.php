<?php
declare(strict_types=1);
namespace app\command;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
final class ClearDate extends Command
{
    protected function configure() {
        $this->setName('ticketing:clear-date')->addOption('date',null,Option::VALUE_REQUIRED)
            ->addOption('scope',null,Option::VALUE_REQUIRED,'all, tickets or timetable','all')
            ->addOption('execute',null,Option::VALUE_NONE,'Delete data; without this flag only preview counts');
    }
    protected function execute(Input $input,Output $output) {
        $result=(new \app\service\DateCleanup())->run((string)$input->getOption('date'),(string)$input->getOption('scope'),!$input->getOption('execute'));
        $output->writeln(json_encode($result,JSON_UNESCAPED_UNICODE));
        return 0;
    }
}
