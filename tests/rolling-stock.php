<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
(new think\App(dirname(__DIR__)))->initialize();
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, (string) $e . PHP_EOL);
    exit(1);
});
use think\facade\Db;
use app\service\{RollingStock, Ticketing, InventoryHolds, FarePricing, StationDictionary};
function ok(bool $v, string $message): void
{
    if (!$v) {
        throw new RuntimeException($message);
    }
    echo 'PASS ' . $message . PHP_EOL;
}
function reject(callable $fn, int $status, string $message): void
{
    try {
        $fn();
    } catch (think\exception\HttpException $e) {
        ok($e->getStatusCode() === $status, $message . ' (' . $e->getStatusCode() . ')');
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $message);
}
Db::startTrans();
try {
    ok(
        RollingStock::code(['train_custom_id' => 'Custom_Train', 'train_type' => 'base_train']) === 'custom_train' &&
            RollingStock::code(['train_type' => 'base_train']) === 'base_train',
        'MTR custom train ID takes precedence over base type',
    );
    $source = Db::table('trips')->where('active', 1)->find();
    ok((bool) $source, 'Imported trip available');
    $original = $source['id'];
    unset($source['id']);
    $tag = bin2hex(random_bytes(5));
    $source['dimension'] = 'test/stock-' . $tag;
    $source['stock_code'] = 'custom_train_' . $tag;
    $source['service_date'] = '2099-07-01';
    $id = (int) Db::table('trips')->insertGetId($source);
    $trip = $source + ['id' => $id];
    $template = Db::table('stops')->where('trip_id', $original)->order('seq')->find();
    $station = Db::table('stations')->where('id', $template['station_id'])->find();
    $codes = [];
    for ($i = 17575; $i >= 0 && count($codes) < 3; $i--) {
        $c = chr(65 + intdiv($i, 676)) . chr(65 + intdiv($i % 676, 26)) . chr(65 + ($i % 26));
        if (!Db::table('station_dictionary')->where('telegraph_code', $c)->find()) {
            $codes[] = $c;
        }
    }
    for ($i = 0; $i < 3; $i++) {
        $station['id'] = $source['dimension'] . ':' . $i;
        $station['dimension'] = $source['dimension'];
        Db::table('stations')->insert($station);
        StationDictionary::save([
            'station_id' => $station['id'],
            'visible' => true,
            'operator_code' => $source['operator_code'],
            'telegraph_code' => $codes[$i],
            'name_zh' => '库存测试' . $i,
            'name_en' => 'Stock ' . $i,
            'admin_level_1' => '',
            'admin_level_2' => '',
        ]);
        $s = $template;
        $s['trip_id'] = $id;
        $s['seq'] = $i;
        $s['station_id'] = $station['id'];
        $s['arrival_at'] = '2099-07-01 10:0' . $i . ':00';
        $s['departure_at'] = '2099-07-01 10:0' . $i . ':30';
        $s['distance_m'] = $i * 40;
        $s['speed_distances_m'] = json_encode([40 => $i * 40]);
        Db::table('stops')->insert($s);
    }
    $agency = 'ST' . $tag;
    $prefix = null;
    $used = Db::table('agencies')->column('issuer_code');
    for ($i = 100; $i < 999; $i++) {
        if (!in_array((string) $i, $used, true)) {
            $prefix = (string) $i;
            break;
        }
    }
    Db::table('agencies')->insert(['code' => $agency, 'name' => 'Stock fixture', 'issuer_code' => $prefix]);
    Db::table('agency_operators')->insert(['agency_code' => $agency, 'operator_code' => $source['operator_code']]);
    $save = fn($kind, $rows) => RollingStock::save([
        'version' => RollingStock::version(),
        'kind' => $kind,
        'rows' => $rows,
    ]);
    $key = ['operator_code' => $trip['operator_code'], 'stock_code' => $trip['stock_code'], 'seat_code' => 'FIRST'];
    $seats = RollingStock::seats($trip);
    ok(
        array_column($seats, 'seat_code') === ['STANDING', 'SECOND'] && array_column($seats, 'capacity') === [100, 400],
        'Default seats: standing 100, second 400, higher classes hidden',
    );
    $save('seat_settings', [$key + ['enabled' => true, 'capacity' => 1]]);
    ok(RollingStock::seat($trip, 'FIRST')['multiplier_bp'] === 15000, 'First class defaults to 1.5');
    $other = $trip;
    $other['operator_code'] = 'unused';
    reject(fn() => RollingStock::seat($other, 'FIRST'), 409, 'Seat enablement isolated by operator');
    $other = $trip;
    $other['depot_id'] = 'another-depot';
    ok(RollingStock::seat($other, 'FIRST')['capacity'] === 1, 'Same train ID shares settings across depots');
    $rules = [
        ['operator_code' => '', 'stock_code' => $trip['stock_code'], 'seat_code' => 'FIRST', 'multiplier_bp' => 17000],
        [
            'operator_code' => $trip['operator_code'],
            'stock_code' => '',
            'seat_code' => 'FIRST',
            'multiplier_bp' => 19000,
        ],
        $key + ['multiplier_bp' => 21000],
    ];
    $save('fare_rules', $rules);
    ok(RollingStock::seat($trip, 'FIRST')['multiplier_bp'] === 21000, 'Operator and stock override wins');
    $save('fare_rules', [$key + ['reset' => true]]);
    ok(RollingStock::seat($trip, 'FIRST')['multiplier_bp'] === 19000, 'Operator generic precedes stock generic');
    $save('fare_rules', [$rules[1] + ['reset' => true]]);
    ok(RollingStock::seat($trip, 'FIRST')['multiplier_bp'] === 17000, 'Stock generic precedes global default');
    $save('fare_rules', [$rules[0] + ['reset' => true]]);
    ok(RollingStock::seat($trip, 'FIRST')['multiplier_bp'] === 15000, 'Removing overrides restores global default');
    reject(
        fn() => $save('fare_rules', [
            [
                'operator_code' => $trip['operator_code'],
                'stock_code' => '',
                'seat_code' => 'SECOND',
                'multiplier_bp' => 12000,
            ],
        ]),
        422,
        'Published fares cannot be overridden',
    );
    $q = FarePricing::quote(
        ['speed_distances_m' => '{}'],
        ['speed_distances_m' => '{"40":40}'],
        ['version' => 1, 'currency' => 'CNY', 'rates' => [['speed_kmh' => 40, 'rate_minor_per_km' => 15]]],
        15000,
    );
    ok($q['amount_minor'] === 1, 'Multiplier applied before the single final rounding');
    $service = new Ticketing();
    $body = [
        'trip_id' => $id,
        'origin_seq' => 0,
        'destination_seq' => 1,
        'seat_code' => 'FIRST',
        'passenger' => 'Stock test',
    ];
    $holdBody = array_diff_key($body, ['passenger' => true]);
    $hold = InventoryHolds::create($holdBody, 'hold-' . $tag, $agency);
    ok(
        $service->available($trip, 0, 1, 'FIRST') === 0 && $service->available($trip, 1, 2, 'FIRST') === 1,
        'Hold consumes its class and interval only',
    );
    ok(InventoryHolds::create($holdBody, 'hold-' . $tag, $agency)['id'] === $hold['id'], 'Hold creation is idempotent');
    reject(fn() => $service->issue($body, 'blocked-' . $tag, $agency), 409, 'Active hold blocks direct overselling');
    reject(
        fn() => $save('seat_settings', [$key + ['enabled' => false, 'capacity' => 0]]),
        409,
        'Cannot disable a held class',
    );
    reject(fn() => InventoryHolds::cancel($hold['id'], 'OTHER'), 404, 'Reservation agency isolation');
    $issued = $service->issue($body + ['hold_id' => $hold['id']], 'issue-' . $tag, $agency);
    ok(
        $issued['coupons'][0]['seat_code'] === 'FIRST' && $issued['fare_snapshot']['seating_mode'] === 'unassigned',
        'Hold converts atomically to an unassigned first class ticket',
    );
    ok(
        $service->issue($body + ['hold_id' => $hold['id']], 'issue-' . $tag, $agency)['id'] === $issued['id'],
        'Consumed hold remains safe for issue retry',
    );
    reject(
        fn() => $service->issue($body + ['hold_id' => $hold['id']], 'reuse-' . $tag, $agency),
        409,
        'Hold cannot be consumed twice',
    );
    $second = $service->issue(array_replace($body, ['seat_code' => 'SECOND']), 'second-' . $tag, $agency);
    ok($service->available($trip, 0, 1, 'SECOND') === 399, 'Classes have independent inventories');
    $adjacent = $service->issue(
        array_replace($body, ['origin_seq' => 1, 'destination_seq' => 2]),
        'adjacent-' . $tag,
        $agency,
    );
    ok(
        $service->available($trip, 0, 2, 'FIRST') === 0,
        'Adjacent tickets reuse one seat; through interval checks every segment',
    );
    reject(
        fn() => $save('seat_settings', [$key + ['enabled' => true, 'capacity' => 0]]),
        409,
        'Cannot reduce capacity below issued inventory',
    );
    $service->refund($issued['ticket_number'], $agency);
    ok($service->available($trip, 0, 1, 'FIRST') === 1, 'Refund restores only refunded class and interval');
    $service->refund($adjacent['ticket_number'], $agency);
    $hold2 = InventoryHolds::create($holdBody, 'expire-' . $tag, $agency);
    Db::table('inventory_holds')
        ->where('id', $hold2['id'])
        ->update(['expires_at' => '2000-01-01 00:00:00']);
    ok($service->available($trip, 0, 1, 'FIRST') === 1, 'Expiry releases inventory without a scheduled job');
    reject(
        fn() => $service->issue($body + ['hold_id' => $hold2['id']], 'expired-' . $tag, $agency),
        409,
        'Expired hold cannot issue',
    );
    $hold3 = InventoryHolds::create($holdBody, 'cancel-' . $tag, $agency);
    InventoryHolds::cancel($hold3['id'], $agency);
    ok($service->available($trip, 0, 1, 'FIRST') === 1, 'Cancellation releases inventory');
    $version = RollingStock::version();
    $hold4 = InventoryHolds::create($holdBody, 'stale-' . $tag, $agency);
    $save('fare_rules', [$key + ['multiplier_bp' => 20000]]);
    reject(
        fn() => $service->issue($body + ['hold_id' => $hold4['id']], 'stale-' . $tag, $agency),
        409,
        'Changed seat rate invalidates outstanding reservation',
    );
    InventoryHolds::cancel($hold4['id'], $agency);
    reject(
        fn() => $service->issue($body + ['stock_version' => $version], 'stale-query-' . $tag, $agency),
        409,
        'Stale seat quote rejected',
    );
    $save('seat_types', [
        [
            'code' => 'CUSTOM_' . strtoupper($tag),
            'name' => '新种别',
            'default_capacity' => 50,
            'multiplier_bp' => 22000,
            'sort_order' => 9,
        ],
    ]);
    ok(count(RollingStock::seats($trip)) === 3, 'New seat type stays disabled until explicitly enabled');
    $stations = Db::table('stops')->where('trip_id', $id)->order('seq')->column('station_id');
    $results = $service->search($stations[0], $stations[1], '2099-07-01');
    $r = array_values(array_filter($results, fn($r) => (int) $r['id'] === $id))[0];
    ok(
        array_column($r['seat_options'], 'seat_code') === ['STANDING', 'SECOND', 'FIRST'],
        'Journey query exposes enabled classes only',
    );
    $save('seat_settings', [$key + ['enabled' => false, 'capacity' => 0]]);
    reject(fn() => $service->issue($body, 'disabled-' . $tag, $agency), 409, 'Disabled class cannot be issued');
    echo 'PASS All stock configuration and inventory changes roll back' . PHP_EOL;
} finally {
    Db::rollback();
}
