<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
$app = new think\App(dirname(__DIR__));
$app->initialize();
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
});
use app\service\FarePricing;
use app\service\Ticketing;
use think\facade\Db;
function fareCheck(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
    echo 'PASS ' . $message . PHP_EOL;
}
function fareReject(callable $fn, int $status, string $message): void
{
    try {
        $fn();
    } catch (think\exception\HttpException $e) {
        fareCheck($e->getStatusCode() === $status, $message);
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $message);
}
$policy = [
    'version' => 1,
    'currency' => 'CNY',
    'rates' => [['speed_kmh' => 40, 'rate_minor_per_km' => 15], ['speed_kmh' => 80, 'rate_minor_per_km' => 25]],
];
$q = FarePricing::quote(['speed_distances_m' => '{}'], ['speed_distances_m' => '{"40":1000,"80":500}'], $policy);
fareCheck(
    $q['amount_minor'] === 28 && $q['distance_m'] === 1500,
    'Mixed-speed fare sums exact products before half-up rounding',
);
fareReject(
    fn() => FarePricing::quote(['speed_distances_m' => null], ['speed_distances_m' => '{}'], $policy),
    409,
    'Missing mileage never falls back to flat segment pricing',
);
fareReject(
    fn() => FarePricing::quote(['speed_distances_m' => '{"40":100}'], ['speed_distances_m' => '{"40":50}'], $policy),
    409,
    'Negative segment mileage rejected',
);
Db::startTrans();
try {
    $before = FarePricing::policy();
    $rates = $before['rates'];
    $invalid = $rates;
    $invalid[1]['rate_minor_per_km'] = $invalid[0]['rate_minor_per_km'];
    fareReject(
        fn() => FarePricing::update(['version' => $before['version'], 'rates' => $invalid]),
        422,
        'Rates must strictly increase with speed',
    );
    fareReject(
        fn() => FarePricing::update(['version' => $before['version'], 'rates' => array_slice($rates, 1)]),
        422,
        'Missing speed bands rejected',
    );
    $source = Db::table('trips')->where('active', 1)->find();
    unset($source['id']);
    $source['dimension'] = 'test/fare-' . bin2hex(random_bytes(6));
    $source['service_date'] = '2099-06-01';
    $source['capacity'] = 10;
    $trip = (int) Db::table('trips')->insertGetId($source);
    $template = Db::table('stops')->where('trip_id', '<>', $trip)->order('trip_id,seq')->limit(2)->select()->toArray();
    $codes = [];
    for ($i = 17575; $i >= 0 && count($codes) < 2; $i--) {
        $code = chr(65 + intdiv($i, 676)) . chr(65 + intdiv($i % 676, 26)) . chr(65 + ($i % 26));
        if (!Db::table('station_dictionary')->where('telegraph_code', $code)->find()) {
            $codes[] = $code;
        }
    }
    foreach ($template as $seq => $stop) {
        $station = Db::table('stations')->where('id', $stop['station_id'])->find();
        $station['id'] = $source['dimension'] . ':' . $seq;
        $station['dimension'] = $source['dimension'];
        Db::table('stations')->insert($station);
        $stop['station_id'] = $station['id'];
        app\service\StationDictionary::save([
            'station_id' => $station['id'],
            'visible' => true,
            'operator_code' => $source['operator_code'],
            'telegraph_code' => $codes[$seq],
            'name_zh' => '计价测试站' . $seq,
            'name_en' => 'Fare fixture ' . $seq,
            'admin_level_1' => '',
            'admin_level_2' => '',
        ]);
        $stop['trip_id'] = $trip;
        $stop['seq'] = $seq;
        $stop['arrival_at'] = '2099-06-01 10:0' . $seq . ':00';
        $stop['departure_at'] = '2099-06-01 10:0' . $seq . ':30';
        $stop['distance_m'] = $seq ? 1500 : 0;
        $stop['speed_distances_m'] = $seq ? '{"40":1000,"80":500}' : '{}';
        Db::table('stops')->insert($stop);
    }
    // Missing fare metadata must not erase physical inventory.
    $fixtureStops = Db::table('stops')->where('trip_id', $trip)->order('seq')->select()->toArray();
    Db::table('stops')
        ->where('trip_id', $trip)
        ->where('seq', 1)
        ->update(['speed_distances_m' => null]);
    $journeys = (new Ticketing())->search($fixtureStops[0]['station_id'], $fixtureStops[1]['station_id'], '2099-06-01');
    $matched = array_values(array_filter($journeys, fn($row) => (int) $row['id'] === $trip));
    fareCheck(
        count($matched) === 1 && $matched[0]['available'] === 10 && $matched[0]['fare_available'] === false,
        'Missing mileage preserves inventory while disabling fare quotation',
    );
    Db::table('stops')
        ->where('trip_id', $trip)
        ->where('seq', 1)
        ->update(['speed_distances_m' => $fixtureStops[1]['speed_distances_m']]);
    $used = Db::table('agencies')->column('issuer_code');
    $prefix = null;
    for ($i = 100; $i < 999; $i++) {
        if (!in_array((string) $i, $used, true)) {
            $prefix = (string) $i;
            break;
        }
    }
    $agency = 'F' . bin2hex(random_bytes(4));
    Db::table('agencies')->insert(['code' => $agency, 'name' => 'Fare test', 'issuer_code' => $prefix]);
    Db::table('agency_operators')->insert(['agency_code' => $agency, 'operator_code' => $source['operator_code']]);
    $service = new Ticketing();
    $body = [
        'trip_id' => $trip,
        'origin_seq' => 0,
        'destination_seq' => 1,
        'passenger' => 'Fare test',
        'fare_version' => $before['version'],
    ];
    $key = 'fare-' . bin2hex(random_bytes(8));
    $ticket = $service->issue($body, $key, $agency);
    fareCheck(
        $ticket['fare_snapshot']['fare_method'] === 'speed_mileage' &&
            $ticket['amount_minor'] === $ticket['fare_snapshot']['amount_minor'],
        'Issued ticket persists mileage, rate version and amount snapshot',
    );
    foreach ($rates as &$rate) {
        $rate['rate_minor_per_km']++;
    }
    unset($rate);
    $after = FarePricing::update(['version' => $before['version'], 'rates' => $rates]);
    fareCheck($after['version'] === $before['version'] + 1, 'Rate update increments version');
    fareReject(
        fn() => FarePricing::update(['version' => $before['version'], 'rates' => $rates]),
        409,
        'Concurrent stale rate edit rejected',
    );
    fareReject(
        fn() => $service->issue($body, $key . '-new', $agency),
        409,
        'Stale quote cannot silently issue at a changed price',
    );
    $again = $service->issue($body, $key, $agency);
    fareCheck(
        $again['ticket_number'] === $ticket['ticket_number'] &&
            $again['amount_minor'] === $ticket['amount_minor'] &&
            $again['fare_snapshot'] === $ticket['fare_snapshot'],
        'Idempotent retry and sold fare survive later rate changes',
    );
} finally {
    Db::rollback();
}
fareCheck(FarePricing::policy() === $before, 'Fare test database changes rolled back');
