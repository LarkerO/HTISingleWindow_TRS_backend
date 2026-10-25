<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
});
use app\mtr\Timetable;
use app\mtr\WorldReader;
function checkMotion(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
    echo "PASS $message\n";
}
$data = (new WorldReader())->read(dirname(__DIR__, 2) . '/world/mtr/minecraft/overworld');
$planner = new Timetable();
$tested = 0;
$keys = [];
foreach ($data['sidings'] as $s) {
    foreach ($s['path'] ?? [] as $p) {
        foreach (array_keys($p['rail']) as $key) {
            $keys[$key] = true;
        }
    }
    try {
        $calls = $planner->stops($s, $data['platforms'], $data['stations']);
    } catch (RuntimeException $e) {
        continue;
    }
    $last = -1.;
    foreach ($calls as $call) {
        checkMotion($call['distance_m'] >= $last, 'Monotonic mileage');
        $last = $call['distance_m'];
    }
    $s['acceleration_constant'] = 0;
    $zero = $planner->stops($s, $data['platforms'], $data['stations']);
    $s['acceleration_constant'] = 0.01;
    checkMotion(
        $zero === $planner->stops($s, $data['platforms'], $data['stations']),
        'Zero acceleration falls back to MTR default',
    );
    $s['acceleration_constant'] = -1;
    checkMotion(
        $zero === $planner->stops($s, $data['platforms'], $data['stations']),
        'Negative acceleration falls back to MTR default',
    );
    $tested++;
    if ($tested >= 3) {
        break;
    }
}
checkMotion($tested === 3, 'Real siding motion fixtures');
echo 'Rail fields: ' . implode(',', array_keys($keys)) . "\n";

$rail = ['t_start_1' => 0, 't_end_1' => 100, 't_start_2' => 0, 't_end_2' => 0, 'rail_type' => 'IRON'];
$fixture = ['train_type' => 'train_19_2', 'rail_length' => 20, 'path' => []];
foreach ([1, 2] as $id) {
    $r = $rail;
    $r['t_end_1'] = $id === 1 ? 100 : 1000;
    $fixture['path'][] = [
        'rail' => $r,
        'dwell_time' => 2,
        'saved_rail_base_id' => $id,
        'stop_index' => $id,
        'starting_pos' => $id,
        'ending_pos' => $id + 1,
    ];
}
$platforms = [1 => ['id' => 1, 'pos_1' => 0, 'pos_2' => 0], 2 => ['id' => 2, 'pos_1' => 0, 'pos_2' => 0]];
$stations = [['id' => 1, 'x_min' => -1, 'x_max' => 1, 'z_min' => -1, 'z_max' => 1]];
$calls = $planner->stops($fixture, $platforms, $stations);
checkMotion(abs($calls[1]['distance_m'] - $calls[0]['distance_m'] - 1000) < 0.001, 'Exact 1000 m path mileage');
$fixture['acceleration_constant'] = 0.001;
$slow = $planner->stops($fixture, $platforms, $stations);
checkMotion(
    $slow[1]['arrival_offset_ms'] - $slow[0]['departure_offset_ms'] >
        $calls[1]['arrival_offset_ms'] - $calls[0]['departure_offset_ms'],
    'Lower acceleration increases running time',
);
checkMotion(
    $calls[0]['departure_offset_ms'] - $calls[0]['arrival_offset_ms'] === 1000,
    'MTR dwell units are half seconds',
);

$fixture['acceleration_constant'] = 0.01;
$fixture['rail_length'] = 50;
$fixture['train_type'] = 'c_train';
$legacy = $planner->stops($fixture, $platforms, $stations);
$fixture['train_type'] = 'train_24_2';
checkMotion($legacy === $planner->stops($fixture, $platforms, $stations), 'Legacy C_TRAIN uses TrainType spacing');
