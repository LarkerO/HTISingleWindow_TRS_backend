<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
$app = new think\App(dirname(__DIR__));
$app->initialize();
use think\facade\Db;
use app\service\StationDictionary as S;
use app\service\Ticketing;
function check($ok, $message)
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
    echo "PASS $message\n";
}
function rejects($fn, $status)
{
    try {
        $fn();
    } catch (think\exception\HttpException $e) {
        check($e->getStatusCode() === $status, "Reject $status");
        return;
    }
    throw new RuntimeException('Expected rejection');
}
Db::startTrans();
try {
    $source = Db::table('trips')->where('active', 1)->find();
    $operator = $source['operator_code'];
    $originalId = $source['id'];
    unset($source['id']);
    $suffix = bin2hex(random_bytes(6));
    $source['dimension'] = 'test/dict-' . $suffix;
    $source['service_date'] = '2099-06-01';
    $source['capacity'] = 10;
    $trip = (int) Db::table('trips')->insertGetId($source);
    $codes = [];
    for ($i = 0; $i < 17576 && count($codes) < 2; $i++) {
        $c = chr(65 + intdiv($i, 676)) . chr(65 + intdiv($i % 676, 26)) . chr(65 + ($i % 26));
        if (!Db::table('station_dictionary')->where('telegraph_code', $c)->find()) {
            $codes[] = $c;
        }
    }
    $templates = Db::table('stops')->where('trip_id', $originalId)->order('seq')->limit(2)->select()->toArray();
    $bodies = [];
    foreach ($templates as $seq => $stop) {
        $id = 'test/dict-' . $suffix . ':' . $seq;
        Db::table('stations')->insert([
            'id' => $id,
            'mtr_id' => (string) $seq,
            'dimension' => 'test/dict-' . $suffix,
            'name' => 'Imported ' . $seq,
        ]);
        $body = [
            'station_id' => $id,
            'operator_code' => $operator,
            'telegraph_code' => strtolower($codes[$seq]),
            'name_zh' => '测试站' . $seq,
            'name_en' => 'Test ' . $seq,
            'admin_level_1' => '一级',
            'admin_level_2' => '二级',
            'visible' => true,
        ];
        rejects(fn() => S::resolve($id), 422);
        S::save($body);
        $bodies[] = $body;
        check(S::resolve(strtolower($codes[$seq])) === $id, 'Case insensitive telegraph lookup');
        $stop['trip_id'] = $trip;
        $stop['seq'] = $seq;
        $stop['station_id'] = $id;
        $stop['arrival_at'] = '2099-06-01 10:0' . $seq . ':00';
        $stop['departure_at'] = '2099-06-01 10:0' . $seq . ':30';
        $stop['distance_m'] = $seq * 1000;
        $stop['speed_distances_m'] = $seq ? ' {"40":1000}' : '{}';
        Db::table('stops')->insert($stop);
    }
    rejects(fn() => S::save(array_replace($bodies[1], ['telegraph_code' => $codes[0]])), 409);
    rejects(fn() => S::save(array_replace($bodies[0], ['telegraph_code' => '12A'])), 422);
    rejects(fn() => S::save(array_replace($bodies[0], ['operator_code' => 'MISSING'])), 422);
    rejects(fn() => S::save(array_replace($bodies[0], ['visible' => 1])), 422);
    check(count(S::listing(false, $codes[0])['data']) === 1, 'Public search by code');
    check(S::listing(false, '测试站')['total'] === 2, 'Chinese station search supports ASCII codes');
    $service = new Ticketing();
    $results = $service->search($codes[0], $codes[1], '2099-06-01');
    check(count(array_filter($results, fn($r) => (int) $r['id'] === $trip)) === 1, 'Journey lookup by telegraph codes');
    $loop = Db::table('stops')->where('trip_id', $trip)->where('seq', 0)->find();
    $loop['seq'] = 2;
    Db::table('stops')->insert($loop);
    rejects(fn() => S::interval(['trip_id' => $trip, 'origin' => $codes[0]]), 422);
    check(
        S::interval(['trip_id' => $trip, 'origin' => $codes[0], 'origin_seq' => 0])['origin_seq'] === 0,
        'Repeated station accepts explicit matching sequence',
    );
    Db::table('stops')->where('trip_id', $trip)->where('seq', 2)->delete();
    $agency = 'D' . $suffix;
    $prefix = null;
    for ($i = 100; $i < 999; $i++) {
        if (!Db::table('agencies')->where('issuer_code', (string) $i)->find()) {
            $prefix = (string) $i;
            break;
        }
    }
    Db::table('agencies')->insert(['code' => $agency, 'name' => 'Dictionary test', 'issuer_code' => $prefix]);
    Db::table('agency_operators')->insert(['agency_code' => $agency, 'operator_code' => $operator]);
    $body = ['trip_id' => $trip, 'origin' => $codes[0], 'destination' => $codes[1], 'passenger' => 'Dictionary test'];
    $key = 'dict-' . $suffix;
    $ticket = $service->issue($body, $key, $agency);
    check(
        $ticket['coupons'][0]['origin']['station']['telegraph_code'] === $codes[0],
        'Issue and ticket lookup include station dictionary',
    );
    check($service->issue($body, $key, $agency)['ticket_number'] === $ticket['ticket_number'], 'Idempotent code issue');
    rejects(fn() => S::interval($body + ['origin_seq' => 1]), 422);
    S::save(array_replace($bodies[0], ['visible' => false]));
    check(
        $service->issue($body, $key, $agency)['ticket_number'] === $ticket['ticket_number'],
        'Idempotent retry survives hiding',
    );
    check(S::listing(false, $codes[0])['total'] === 0, 'Hidden station removed from public list');
    check(S::listing(true, $codes[0])['total'] === 1, 'Admin can edit hidden station');
    rejects(fn() => $service->search($codes[0], $codes[1], '2099-06-01'), 422);
    rejects(
        fn() => $service->issue(
            ['trip_id' => $trip, 'origin_seq' => 0, 'destination_seq' => 1, 'passenger' => 'Test'],
            $key . '-hidden',
            $agency,
        ),
        422,
    );
    check(
        $service->get($ticket['ticket_number'], $agency)['ticket_number'] === $ticket['ticket_number'],
        'Historical ticket lookup survives hiding',
    );
    check($service->refund($ticket['ticket_number'], $agency)['status'] === 'RFND', 'Refund survives hiding');
} finally {
    Db::rollback();
}
echo "PASS All dictionary fixtures rolled back\n";
