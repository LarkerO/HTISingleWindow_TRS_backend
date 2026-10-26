<?php
/** Isolated public stations for stock tests; caller rolls back or deletes this dimension. */
function publicStopFixture(array $stops, string $dimension, string $operator): array
{
    $codes = [];
    for ($i = 17575; $i >= 0 && count($codes) < count($stops); $i--) {
        $c = chr(65 + intdiv($i, 676)) . chr(65 + intdiv($i % 676, 26)) . chr(65 + ($i % 26));
        if (!\think\facade\Db::table('station_dictionary')->where('telegraph_code', $c)->find()) {
            $codes[] = $c;
        }
    }
    foreach ($stops as $i => &$stop) {
        $station = \think\facade\Db::table('stations')->where('id', $stop['station_id'])->find();
        $station['id'] = $dimension . ':' . $i;
        $station['dimension'] = $dimension;
        \think\facade\Db::table('stations')->insert($station);
        \app\service\StationDictionary::save([
            'station_id' => $station['id'],
            'visible' => true,
            'operator_code' => $operator,
            'telegraph_code' => $codes[$i],
            'name_zh' => '测试站' . $i,
            'name_en' => 'Fixture ' . $i,
            'admin_level_1' => '',
            'admin_level_2' => '',
        ]);
        $stop['station_id'] = $station['id'];
    }
    return $stops;
}
