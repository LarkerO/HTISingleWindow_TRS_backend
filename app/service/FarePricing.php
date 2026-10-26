<?php
declare(strict_types=1);
namespace app\service;
use think\facade\Db;
use think\exception\HttpException;
final class FarePricing
{
    public const DEFAULT_RATES = [
        2 => 5,
        20 => 10,
        30 => 12,
        40 => 15,
        60 => 20,
        80 => 25,
        120 => 35,
        160 => 45,
        200 => 55,
        300 => 75,
        900 => 150,
    ];
    public static function migrate(): void
    {
        Db::execute('ALTER TABLE stops ADD COLUMN IF NOT EXISTS speed_distances_m LONGTEXT NULL');
        Db::execute('ALTER TABLE tickets ADD COLUMN IF NOT EXISTS fare_snapshot LONGTEXT NULL');
        Db::execute(
            'CREATE TABLE IF NOT EXISTS fare_policy (id INT PRIMARY KEY, version INT NOT NULL, updated_at DATETIME NOT NULL) ENGINE=InnoDB',
        );
        Db::execute(
            'CREATE TABLE IF NOT EXISTS fare_rates (speed_kmh INT PRIMARY KEY, rate_minor_per_km INT NOT NULL) ENGINE=InnoDB',
        );
        Db::execute('INSERT IGNORE INTO fare_policy (id,version,updated_at) VALUES (1,1,UTC_TIMESTAMP())');
        foreach (self::DEFAULT_RATES as $speed => $rate) {
            Db::execute('INSERT IGNORE INTO fare_rates (speed_kmh,rate_minor_per_km) VALUES (?,?)', [$speed, $rate]);
        }
    }
    public static function policy(bool $lock = false): array
    {
        if ($lock) {
            Db::table('fare_policy')->where('id', 1)->lock(true)->find();
        }
        $rows = Db::query(
            'SELECT r.speed_kmh,r.rate_minor_per_km,p.version FROM fare_rates r JOIN fare_policy p ON p.id=1 ORDER BY r.speed_kmh',
        );
        $rates = [];
        foreach ($rows as $row) {
            $rates[] = ['speed_kmh' => (int) $row['speed_kmh'], 'rate_minor_per_km' => (int) $row['rate_minor_per_km']];
        }
        return [
            'version' => (int) ($rows[0]['version'] ?? 0),
            'currency' => (string) config('ticketing.currency'),
            'rates' => $rates,
            'basis' => 'nominal_rail_speed',
            'rounding' => 'sum_then_half_up_to_minor_unit',
        ];
    }
    public static function update(array $body): array
    {
        if (!isset($body['version'], $body['rates']) || !is_int($body['version']) || !is_array($body['rates'])) {
            throw new HttpException(422, 'version and full rates array required');
        }
        $rates = [];
        foreach ($body['rates'] as $row) {
            if (
                !is_array($row) ||
                !isset($row['speed_kmh'], $row['rate_minor_per_km']) ||
                !is_int($row['speed_kmh']) ||
                !is_int($row['rate_minor_per_km']) ||
                !array_key_exists($row['speed_kmh'], self::DEFAULT_RATES) ||
                isset($rates[$row['speed_kmh']]) ||
                $row['rate_minor_per_km'] < 0 ||
                $row['rate_minor_per_km'] > 1000000
            ) {
                throw new HttpException(422, 'Invalid or duplicate speed/rate');
            }
            $rates[$row['speed_kmh']] = $row['rate_minor_per_km'];
        }
        ksort($rates, SORT_NUMERIC);
        if (array_keys($rates) !== array_keys(self::DEFAULT_RATES)) {
            throw new HttpException(422, 'All speed bands are required');
        }
        $last = -1;
        foreach ($rates as $rate) {
            if ($rate <= $last) {
                throw new HttpException(422, 'Per-km rates must strictly increase with speed');
            }
            $last = $rate;
        }
        return Db::transaction(function () use ($body, $rates) {
            $current = self::policy(true);
            if ($current['version'] !== $body['version']) {
                throw new HttpException(409, 'Fare policy changed; reload before saving');
            }
            foreach ($rates as $speed => $rate) {
                Db::table('fare_rates')
                    ->where('speed_kmh', $speed)
                    ->update(['rate_minor_per_km' => $rate]);
            }
            Db::table('fare_policy')
                ->where('id', 1)
                ->update(['version' => $current['version'] + 1, 'updated_at' => gmdate('Y-m-d H:i:s')]);
            return self::policy();
        });
    }
    public static function quote(
        array $origin,
        array $destination,
        ?array $policy = null,
        int $multiplierBp = 10000,
    ): array {
        $read = static function ($value) {
            if (is_array($value)) {
                return $value;
            }
            if (is_string($value)) {
                return json_decode($value, true);
            }
            return null;
        };
        $a = $read($origin['speed_distances_m'] ?? null);
        $b = $read($destination['speed_distances_m'] ?? null);
        if (!is_array($a) || !is_array($b)) {
            throw new HttpException(409, 'Speed-band mileage unavailable; import or backfill mileage first');
        }
        $policy ??= self::policy();
        $rates = array_column($policy['rates'], 'rate_minor_per_km', 'speed_kmh');
        $bands = [];
        $numerator = 0;
        $millimetres = 0;
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $speed) {
            $mm = (int) round((float) ($b[$speed] ?? 0) * 1000) - (int) round((float) ($a[$speed] ?? 0) * 1000);
            if ($mm < 0 || !isset($rates[$speed])) {
                throw new HttpException(409, 'Invalid speed-band mileage');
            }
            if ($mm === 0) {
                continue;
            }
            $cost = $mm * $rates[$speed];
            if (!is_int($cost) || $cost > PHP_INT_MAX - $numerator) {
                throw new HttpException(409, 'Fare calculation overflow');
            }
            $numerator += $cost;
            $millimetres += $mm;
            $bands[] = ['speed_kmh' => (int) $speed, 'distance_m' => $mm / 1000, 'rate_minor_per_km' => $rates[$speed]];
        }
        if ($millimetres <= 0) {
            throw new HttpException(409, 'No billable mileage');
        }
        if ($numerator > PHP_INT_MAX - 500000) {
            throw new HttpException(409, 'Fare calculation overflow');
        }
        if ($multiplierBp < 10000 || $multiplierBp > 1000000) {
            throw new HttpException(422, 'Invalid seat multiplier');
        }
        // Split into whole minor units and remainder to avoid a large intermediate product.
        $whole = intdiv($numerator, 1000000);
        if ($whole > intdiv(PHP_INT_MAX - 10000000000, $multiplierBp)) {
            throw new HttpException(409, 'Fare calculation overflow');
        }
        $scaled = $whole * $multiplierBp;
        $amount =
            intdiv($scaled, 10000) +
            intdiv(($scaled % 10000) * 1000000 + ($numerator % 1000000) * $multiplierBp + 5000000000, 10000000000);
        if ($amount > 2147483647) {
            throw new HttpException(409, 'Fare exceeds supported ticket amount');
        }
        return [
            'amount_minor' => $amount,
            'currency' => $policy['currency'],
            'distance_m' => $millimetres / 1000,
            'fare_version' => $policy['version'],
            'fare_breakdown' => $bands,
            'fare_method' => 'speed_mileage',
        ];
    }
}
