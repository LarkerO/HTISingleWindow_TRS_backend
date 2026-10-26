<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
(new think\App(dirname(__DIR__)))->initialize();
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, (string) $e . PHP_EOL);
    exit(1);
});
use app\service\StationDictionary as S;
use think\facade\Db;
function verify(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
    echo 'PASS ' . $message . PHP_EOL;
}
foreach (
    [
        ['广阳|Guangyang', '广阳', 'Guangyang'],
        [' Guangyang | 广阳 ', '广阳', 'Guangyang'],
        ['|§a广阳|| Guangyang |Other language', '广阳', 'Guangyang'],
        ['广阳', '广阳', ''],
        ['Guangyang', '', 'Guangyang'],
        ['', '', ''],
    ]
    as [$name, $zh, $en]
) {
    verify(
        S::splitName($name) === ['name_zh' => $zh, 'name_en' => $en],
        'Parse ' . json_encode($name, JSON_UNESCAPED_UNICODE),
    );
}
Db::startTrans();
try {
    $id = 'test/auto-name-' . bin2hex(random_bytes(6)) . ':1';
    $operator = Db::table('operators')->value('code');
    Db::table('stations')->insert([
        'id' => $id,
        'mtr_id' => '1',
        'dimension' => 'test/auto-name',
        'name' => '广阳|Guangyang',
    ]);
    S::syncName($id);
    $draft = Db::table('station_dictionary')->where('station_id', $id)->find();
    verify(
        $draft['name_zh'] === '广阳' &&
            $draft['name_en'] === 'Guangyang' &&
            $draft['telegraph_code'] === null &&
            $draft['operator_code'] === null &&
            !(bool) $draft['visible'],
        'Create bilingual hidden draft without invented operator or telegraph code',
    );
    verify(S::listing(false, 'Guangyang', 'test/auto-name')['total'] === 0, 'Draft not exposed publicly');
    Db::table('stations')
        ->where('id', $id)
        ->update(['name' => '广阳东|Guangyang East']);
    S::syncName($id);
    $draft = Db::table('station_dictionary')->where('station_id', $id)->find();
    verify(
        $draft['name_zh'] === '广阳东' && $draft['name_en'] === 'Guangyang East',
        'Automatic names follow MTR source changes',
    );
    $code = null;
    for ($i = 0; $i < 17576; $i++) {
        $c = chr(65 + intdiv($i, 676)) . chr(65 + intdiv($i % 676, 26)) . chr(65 + ($i % 26));
        if (!Db::table('station_dictionary')->where('telegraph_code', $c)->find()) {
            $code = $c;
            break;
        }
    }
    $body = [
        'station_id' => $id,
        'operator_code' => $operator,
        'telegraph_code' => $code,
        'visible' => false,
        'name_zh' => $draft['name_zh'],
        'name_en' => $draft['name_en'],
        'admin_level_1' => '',
        'admin_level_2' => '',
    ];
    S::save($body);
    $draft = Db::table('station_dictionary')->where('station_id', $id)->find();
    verify(
        $draft['name_zh_source'] === 'mtr' && $draft['name_en_source'] === 'mtr',
        'Metadata-only edit preserves automatic name ownership',
    );
    S::save(array_replace($body, ['name_zh' => '人工站名']));
    Db::table('stations')
        ->where('id', $id)
        ->update(['name' => '广阳南|Guangyang South']);
    S::syncName($id);
    $draft = Db::table('station_dictionary')->where('station_id', $id)->find();
    verify(
        $draft['name_zh'] === '人工站名' &&
            $draft['name_zh_source'] === 'manual' &&
            $draft['name_en'] === 'Guangyang South',
        'Manual Chinese preserved while automatic English refreshes',
    );
    verify(
        $draft['telegraph_code'] === $code && $draft['operator_code'] === $operator && !(bool) $draft['visible'],
        'Resync preserves operator, code and visibility',
    );
    S::syncName($id);
    verify(Db::table('station_dictionary')->where('station_id', $id)->count() === 1, 'Repeated sync is idempotent');
    $listed = S::listing(true, $code)['data'];
    verify(
        count($listed) === 1 && $listed[0]['name_zh'] === '人工站名' && $listed[0]['name_en'] === 'Guangyang South',
        'Dictionary list exposes materialized bilingual names',
    );
} finally {
    Db::rollback();
}
echo 'PASS All auto-name fixtures rolled back' . PHP_EOL;
