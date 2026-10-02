<?php
declare(strict_types=1);
namespace app\service;
use think\facade\Db;
final class FeatureSchema
{
    public static function migrate(): void {
        Db::execute('ALTER TABLE trips ADD COLUMN IF NOT EXISTS train_number VARCHAR(17) NULL AFTER name');
        Db::execute('ALTER TABLE trips ADD INDEX IF NOT EXISTS train_number_date (train_number,service_date,active)');
        // Keep existing agencies' sales restrictions; newly-created operators default to open.
        Db::execute('ALTER TABLE operators ADD COLUMN IF NOT EXISTS whitelist_enabled TINYINT NOT NULL DEFAULT 1');
        Db::execute('ALTER TABLE mtr_depots ADD COLUMN IF NOT EXISTS operator_code VARCHAR(16) NULL');
        Db::execute('ALTER TABLE operators ADD COLUMN IF NOT EXISTS api_key_hash CHAR(64) NULL');
        Db::execute('ALTER TABLE operators ADD UNIQUE INDEX IF NOT EXISTS operator_api_key_hash (api_key_hash)');
        Db::execute('ALTER TABLE tickets ADD COLUMN IF NOT EXISTS passenger_document_id BIGINT UNSIGNED NULL');
        Db::execute('ALTER TABLE tickets ADD COLUMN IF NOT EXISTS passenger_document_snapshot LONGTEXT NULL');
        $constraint=Db::query("SELECT COUNT(*) total FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='tickets' AND CONSTRAINT_NAME='fk_ticket_passenger_document'")[0]['total'];
        if(!$constraint)Db::execute('ALTER TABLE tickets ADD CONSTRAINT fk_ticket_passenger_document FOREIGN KEY(passenger_document_id) REFERENCES passenger_documents(id)');
        foreach(Db::table('trips')->whereNull('train_number')->field('id,name')->cursor() as $trip) {
            $number=\app\mtr\TrainNumber::extract($trip['name']);
            if($number!==null)Db::table('trips')->where('id',$trip['id'])->update(['train_number'=>$number]);
        }
    }
}

