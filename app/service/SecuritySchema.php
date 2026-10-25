<?php
declare(strict_types=1);
namespace app\service;
use think\facade\Db;
final class SecuritySchema
{
    public static function migrate(): void {
        foreach(['agencies','operators'] as $table){
            Db::execute('ALTER TABLE '.$table.' ADD COLUMN IF NOT EXISTS api_key_id CHAR(64) NULL');
            Db::execute('ALTER TABLE '.$table.' ADD UNIQUE INDEX IF NOT EXISTS signing_key_id (api_key_id)');
            foreach(Db::table($table)->whereNotNull('api_key_hash')->field('code,api_key_hash')->cursor() as $row)
                Db::table($table)->where('code',$row['code'])->update(['api_key_id'=>Sm3::keyId($row['api_key_hash'])]);
        }
        Db::execute('CREATE TABLE IF NOT EXISTS request_nonces (key_id CHAR(64) NOT NULL, nonce CHAR(32) NOT NULL, expires_at BIGINT NOT NULL, PRIMARY KEY(key_id,nonce), INDEX nonce_expiry(expires_at)) ENGINE=InnoDB');
    }
}
