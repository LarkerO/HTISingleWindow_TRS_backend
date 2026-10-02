<?php
namespace app\command;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;
final class Schema extends Command
{
    protected function configure() { $this->setName('ticketing:schema'); }
    protected function execute(Input $input,Output $output) {
        foreach(explode(';',file_get_contents(app()->getRootPath().'database/schema.sql')) as $sql) if(trim($sql)) Db::execute($sql);
        foreach(explode(';',file_get_contents(app()->getRootPath().'database/organizations.sql')) as $sql) if(trim($sql)) Db::execute($sql);
        foreach(explode(';',file_get_contents(app()->getRootPath().'database/catalog.sql')) as $sql) if(trim($sql)) Db::execute($sql);
        Db::execute('ALTER TABLE mtr_depots ADD COLUMN IF NOT EXISTS present TINYINT NOT NULL DEFAULT 1');
        foreach(['passengers.sql','ticket_tlv.sql'] as $file)foreach(explode(';',file_get_contents(app()->getRootPath().'database/'.$file)) as $sql)if(trim($sql))Db::execute($sql);
        \app\service\FeatureSchema::migrate();
        \app\service\Organizations::seed();
        \app\service\TripIdentity::migrate();
        $output->writeln('Schema ready'); return 0;
    }
}






