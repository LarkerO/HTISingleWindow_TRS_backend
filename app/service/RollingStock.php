<?php
declare(strict_types=1);
namespace app\service;
use think\facade\Db;
use think\exception\HttpException;
final class RollingStock
{
    public static function code(array $siding): string
    {
        $custom = (string) ($siding['train_custom_id'] ?? '');
        // MTR Siding.setTrainDetails normalizes the ID to lowercase.
        return strtolower($custom !== '' ? $custom : (string) ($siding['train_type'] ?? ''));
    }
    public static function migrate(): void
    {
        Db::execute(
            "ALTER TABLE mtr_depot_sidings ADD COLUMN IF NOT EXISTS stock_code VARCHAR(128) COLLATE utf8mb4_bin NOT NULL DEFAULT ''",
        );
        Db::execute(
            "ALTER TABLE trips ADD COLUMN IF NOT EXISTS stock_code VARCHAR(128) COLLATE utf8mb4_bin NOT NULL DEFAULT ''",
        );
        Db::execute("ALTER TABLE coupons ADD COLUMN IF NOT EXISTS seat_code VARCHAR(32) NOT NULL DEFAULT 'SECOND'");
        Db::execute('ALTER TABLE coupons ADD COLUMN IF NOT EXISTS carriage_number VARCHAR(32) NULL');
        Db::execute('ALTER TABLE coupons ADD COLUMN IF NOT EXISTS seat_number VARCHAR(32) NULL');
        Db::execute('CREATE TABLE IF NOT EXISTS stock_policy (id INT PRIMARY KEY, version INT NOT NULL) ENGINE=InnoDB');
        Db::execute('INSERT IGNORE INTO stock_policy VALUES (1,1)');
        Db::execute(
            'CREATE TABLE IF NOT EXISTS seat_types (code VARCHAR(32) PRIMARY KEY, name VARCHAR(80) NOT NULL, default_capacity INT UNSIGNED NOT NULL, multiplier_bp INT UNSIGNED NOT NULL, sort_order INT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );
        Db::execute(
            'CREATE TABLE IF NOT EXISTS stock_seats (operator_code VARCHAR(16) NOT NULL, stock_code VARCHAR(128) COLLATE utf8mb4_bin NOT NULL, seat_code VARCHAR(32) NOT NULL, enabled TINYINT NOT NULL, capacity INT UNSIGNED NOT NULL, PRIMARY KEY(operator_code,stock_code,seat_code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );
        Db::execute(
            "CREATE TABLE IF NOT EXISTS stock_fare_rules (operator_code VARCHAR(16) NOT NULL DEFAULT '', stock_code VARCHAR(128) COLLATE utf8mb4_bin NOT NULL DEFAULT '', seat_code VARCHAR(32) NOT NULL, multiplier_bp INT UNSIGNED NOT NULL, PRIMARY KEY(operator_code,stock_code,seat_code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        );
        Db::execute(
            "CREATE TABLE IF NOT EXISTS inventory_holds (id CHAR(32) PRIMARY KEY, agency_code VARCHAR(32) NOT NULL, request_key VARCHAR(100) NOT NULL, body_hash CHAR(64) NOT NULL, trip_id BIGINT UNSIGNED NOT NULL, seat_code VARCHAR(32) NOT NULL, origin_seq INT UNSIGNED NOT NULL, destination_seq INT UNSIGNED NOT NULL, policy_version INT NOT NULL, fare_version INT NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'HELD', expires_at DATETIME(3) NOT NULL, ticket_id BIGINT UNSIGNED NULL, UNIQUE KEY hold_request(agency_code,request_key), INDEX occupancy(trip_id,status,expires_at), FOREIGN KEY(trip_id) REFERENCES trips(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        );
        foreach (
            [
                ['STANDING', '无座', 100, 10000, 0],
                ['SECOND', '二等座', 400, 10000, 1],
                ['FIRST', '一等座', 0, 15000, 2],
                ['PREFERRED_FIRST', '优选一等座', 0, 18000, 3],
                ['PREMIUM', '特等座', 0, 30000, 4],
            ]
            as $s
        ) {
            Db::execute('INSERT IGNORE INTO seat_types VALUES (?,?,?,?,?)', $s);
        }
        Db::execute(
            "UPDATE trips t JOIN mtr_depot_sidings s ON s.dimension=t.dimension AND s.depot_id=t.depot_id AND s.siding_id=t.siding_id SET t.stock_code=s.stock_code WHERE t.stock_code='' AND s.stock_code<>''",
        );
    }
    public static function version(bool $lock = false): int
    {
        $q = Db::table('stock_policy')->where('id', 1);
        if ($lock) {
            $q->lock(true);
        }
        return (int) $q->value('version');
    }
    public static function types(): array
    {
        return Db::table('seat_types')->order('sort_order,code')->select()->toArray();
    }
    public static function seats(array $trip): array
    {
        $stock = $trip['stock_code'] ?? '';
        $overrides = array_column(
            Db::table('stock_seats')
                ->where('operator_code', $trip['operator_code'])
                ->where('stock_code', $stock)
                ->select()
                ->toArray(),
            null,
            'seat_code',
        );
        $rules = Db::table('stock_fare_rules')
            ->whereIn('operator_code', ['', $trip['operator_code']])
            ->whereIn('stock_code', ['', $stock])
            ->select()
            ->toArray();
        $out = [];
        foreach (self::types() as $s) {
            $code = $s['code'];
            $base = in_array($code, ['STANDING', 'SECOND'], true);
            $o = $overrides[$code] ?? null;
            if (!($o ? (bool) $o['enabled'] : $base)) {
                continue;
            }
            $multiplier = $base ? 10000 : (int) $s['multiplier_bp'];
            $source = 'default';
            $best = 0;
            if (!$base) {
                foreach ($rules as $r) {
                    $priority = ($r['operator_code'] !== '' ? 2 : 0) + ($r['stock_code'] !== '' ? 1 : 0);
                    if ($r['seat_code'] === $code && $priority > $best) {
                        $multiplier = (int) $r['multiplier_bp'];
                        $source = [1 => 'stock', 2 => 'operator', 3 => 'operator_stock'][$priority];
                        $best = $priority;
                    }
                };
            }
            $out[] = [
                'seat_code' => $code,
                'seat_name' => $s['name'],
                'capacity' => (int) ($o['capacity'] ?? $s['default_capacity']),
                'multiplier_bp' => $multiplier,
                'rate_source' => $source,
                'seating_mode' => 'unassigned',
            ];
        }
        return $out;
    }
    public static function seat(array $trip, string $code): array
    {
        foreach (self::seats($trip) as $s) {
            if ($s['seat_code'] === $code) {
                return $s;
            }
        }
        throw new HttpException(409, 'Seat type is not enabled for this operator and rolling stock');
    }
    public static function listing(): array
    {
        $stocks = Db::query(
            "SELECT DISTINCT s.stock_code stock_code,d.operator_code FROM mtr_depot_sidings s JOIN mtr_depots d ON d.dimension=s.dimension AND d.depot_id=s.depot_id WHERE d.present=1 AND s.stock_code<>'' UNION SELECT DISTINCT stock_code,operator_code FROM trips WHERE active=1 AND stock_code<>'' ORDER BY stock_code,operator_code",
        );
        foreach ($stocks as &$s) {
            $s['seats'] = self::seats(['stock_code' => $s['stock_code'], 'operator_code' => $s['operator_code'] ?? '']);
        }
        return [
            'version' => self::version(),
            'seat_types' => self::types(),
            'stocks' => $stocks,
            'seat_settings' => Db::table('stock_seats')->select()->toArray(),
            'fare_rules' => Db::table('stock_fare_rules')->select()->toArray(),
        ];
    }
    private static function integer($v, string $field, int $max): int
    {
        if (!is_int($v) || $v < 0 || $v > $max) {
            throw new HttpException(422, 'Invalid ' . $field);
        }
        return $v;
    }
    public static function save(array $body): array
    {
        if (!isset($body['version']) || !is_int($body['version'])) {
            throw new HttpException(422, 'version required');
        }
        $kind = $body['kind'] ?? '';
        $rows = $body['rows'] ?? null;
        if (
            !in_array($kind, ['seat_types', 'seat_settings', 'fare_rules'], true) ||
            !is_array($rows) ||
            !array_is_list($rows) ||
            !$rows ||
            count($rows) > 500
        ) {
            throw new HttpException(422, 'kind and 1..500 rows required');
        }
        return Db::transaction(function () use ($body, $kind, $rows) {
            $version = self::version(true);
            if ($version !== $body['version']) {
                throw new HttpException(409, 'Rolling stock policy changed; reload');
            }
            foreach ($rows as $r) {
                if (!is_array($r)) {
                    throw new HttpException(422, 'Invalid row');
                }
                $code = $r[$kind === 'seat_types' ? 'code' : 'seat_code'] ?? '';
                if (!is_string($code) || !preg_match('/^[A-Z][A-Z0-9_]{0,31}$/D', $code)) {
                    throw new HttpException(422, 'Invalid seat code');
                }
                $base = in_array($code, ['STANDING', 'SECOND'], true);
                if ($kind === 'seat_types') {
                    $name = $r['name'] ?? '';
                    if (!is_string($name) || !trim($name) || mb_strlen($name) > 80) {
                        throw new HttpException(422, 'Seat name required (1..80 characters)');
                    }
                    $row = [
                        'code' => $code,
                        'name' => trim($name),
                        'default_capacity' => self::integer($r['default_capacity'] ?? null, 'capacity', 1000000),
                        'multiplier_bp' => self::integer($r['multiplier_bp'] ?? null, 'multiplier_bp', 1000000),
                        'sort_order' => self::integer($r['sort_order'] ?? 0, 'sort_order', 1000000),
                    ];
                    if ($row['multiplier_bp'] < 10000 || ($base && $row['multiplier_bp'] !== 10000)) {
                        throw new HttpException(422, 'Multiplier must be >=1; standing/second fixed at 1');
                    }
                    Db::table('seat_types')->duplicate($row)->insert($row);
                    continue;
                }
                if (!Db::table('seat_types')->where('code', $code)->find()) {
                    throw new HttpException(422, 'Unknown seat type');
                }
                $operator = $r['operator_code'] ?? '';
                $stock = $r['stock_code'] ?? '';
                if (!is_string($operator) || !is_string($stock) || mb_strlen($stock) > 128 || strlen($operator) > 16) {
                    throw new HttpException(422, 'Invalid operator/stock');
                }
                if ($operator !== '' && !Db::table('operators')->where('code', $operator)->find()) {
                    throw new HttpException(422, 'Unknown operator');
                }
                if ($kind === 'seat_settings' && ($operator === '' || $stock === '')) {
                    throw new HttpException(422, 'Operator and stock required');
                }
                if ($kind === 'fare_rules' && ($base || ($operator === '' && $stock === ''))) {
                    throw new HttpException(
                        422,
                        'Base fares cannot be overridden; use seat type defaults for global rates',
                    );
                }
                $key = ['operator_code' => $operator, 'stock_code' => $stock, 'seat_code' => $code];
                $table = $kind === 'seat_settings' ? 'stock_seats' : 'stock_fare_rules';
                if (($r['reset'] ?? false) === true) {
                    Db::table($table)->where($key)->delete();
                    continue;
                }
                if ($kind === 'seat_settings') {
                    if (!isset($r['enabled']) || !is_bool($r['enabled'])) {
                        throw new HttpException(422, 'Boolean enabled required');
                    }
                    $row = $key + [
                        'enabled' => (int) $r['enabled'],
                        'capacity' => self::integer($r['capacity'] ?? null, 'capacity', 1000000),
                    ];
                } else {
                    $multiplier = self::integer($r['multiplier_bp'] ?? null, 'multiplier_bp', 1000000);
                    if ($multiplier < 10000) {
                        throw new HttpException(422, 'Multiplier must be >=1');
                    }
                    $row = $key + ['multiplier_bp' => $multiplier];
                }
                Db::table($table)->duplicate($row)->insert($row);
            }
            foreach (
                Db::table('trips')
                    ->where('active', 1)
                    ->whereRaw(
                        "EXISTS (SELECT 1 FROM coupons c WHERE c.trip_id=trips.id AND c.status='OPEN') OR EXISTS (SELECT 1 FROM inventory_holds h WHERE h.trip_id=trips.id AND h.status='HELD' AND h.expires_at>UTC_TIMESTAMP(3))",
                    )
                    ->select()
                    ->toArray()
                as $trip
            ) {
                $capacities = array_column(self::seats($trip), 'capacity', 'seat_code');
                $occupied = Db::query(
                    "SELECT seat_code,origin_seq,destination_seq FROM coupons WHERE trip_id=? AND status='OPEN' UNION ALL SELECT seat_code,origin_seq,destination_seq FROM inventory_holds WHERE trip_id=? AND status='HELD' AND expires_at>UTC_TIMESTAMP(3)",
                    [$trip['id'], $trip['id']],
                );
                $usage = [];
                foreach ($occupied as $c) {
                    for ($i = (int) $c['origin_seq']; $i < (int) $c['destination_seq']; $i++) {
                        $k = $c['seat_code'];
                        $usage[$k][$i] = ($usage[$k][$i] ?? 0) + 1;
                        if ($usage[$k][$i] > ($capacities[$k] ?? 0)) {
                            throw new HttpException(409, 'Cannot disable or reduce seats below occupied inventory');
                        }
                    };
                }
            }
            Db::table('stock_policy')
                ->where('id', 1)
                ->update(['version' => $version + 1]);
            return self::listing();
        });
    }
}
