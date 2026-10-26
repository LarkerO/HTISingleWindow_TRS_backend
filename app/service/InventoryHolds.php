<?php
declare(strict_types=1);
namespace app\service;
use think\facade\Db;
use think\exception\HttpException;
/** Optional ten-minute checkout reservation; expiry is checked in every occupancy query. */
final class InventoryHolds
{
    public static function forIssue(string $id, string $agency, array $body, int $version): array
    {
        $hold = Db::table('inventory_holds')->where('id', $id)->where('agency_code', $agency)->lock(true)->find();
        if (!$hold) {
            throw new HttpException(404, 'Reservation not found');
        }
        if (
            $hold['status'] !== 'HELD' ||
            $hold['expires_at'] <= (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v')
        ) {
            throw new HttpException(409, 'Reservation expired or already used');
        }
        foreach (['trip_id', 'origin_seq', 'destination_seq'] as $k) {
            if ((int) $hold[$k] !== $body[$k]) {
                throw new HttpException(422, 'Reservation interval mismatch');
            }
        }
        if ($hold['seat_code'] !== ($body['seat_code'] ?? 'SECOND')) {
            throw new HttpException(422, 'Reservation seat mismatch');
        }
        if ((int) $hold['policy_version'] !== $version) {
            throw new HttpException(409, 'Seat policy changed; reserve again');
        }
        return $hold;
    }
    public static function create(array $body, string $key, string $agency): array
    {
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,100}$/D', $key)) {
            throw new HttpException(422, 'Idempotency-Key required');
        }
        foreach (['trip_id', 'origin_seq', 'destination_seq'] as $k) {
            if (!isset($body[$k]) || !is_int($body[$k]) || $body[$k] < 0) {
                throw new HttpException(422, 'Invalid ' . $k);
            }
        }
        $seat = $body['seat_code'] ?? 'SECOND';
        if (!is_string($seat) || !preg_match('/^[A-Z][A-Z0-9_]{0,31}$/D', $seat)) {
            throw new HttpException(422, 'Invalid seat_code');
        }
        $normalized = [
            'trip_id' => $body['trip_id'],
            'origin_seq' => $body['origin_seq'],
            'destination_seq' => $body['destination_seq'],
            'seat_code' => $seat,
        ];
        foreach (['stock_version', 'fare_version'] as $v) {
            if (isset($body[$v])) {
                if (!is_int($body[$v]) || $body[$v] < 1) {
                    throw new HttpException(422, 'Invalid ' . $v);
                }
                $normalized[$v] = $body[$v];
            }
        }
        $hash = hash('sha256', json_encode($normalized));
        return Db::transaction(function () use ($normalized, $key, $agency, $hash, $seat) {
            $version = RollingStock::version(true);
            $trip = Db::table('trips')->where('id', $normalized['trip_id'])->lock(true)->find();
            $existing = Db::table('inventory_holds')->where('agency_code', $agency)->where('request_key', $key)->find();
            if ($existing) {
                if (!hash_equals($existing['body_hash'], $hash)) {
                    throw new HttpException(409, 'Idempotency-Key reused with another request');
                }
                return self::present($existing);
            }
            if (!$trip || !$trip['active']) {
                throw new HttpException(409, 'Trip unavailable');
            }
            $a = Db::table('agencies')->where('code', $agency)->lock(true)->find();
            $operator = Db::table('operators')->where('code', $trip['operator_code'])->lock(true)->find();
            if (!$a || !$a['active'] || !$operator || !$operator['active']) {
                throw new HttpException(403, 'Organization disabled');
            }
            if (
                $operator['whitelist_enabled'] &&
                !Db::table('agency_operators')
                    ->where('agency_code', $agency)
                    ->where('operator_code', $trip['operator_code'])
                    ->find()
            ) {
                throw new HttpException(403, 'Agency not authorized for operator');
            }
            $from = Db::table('stops')->where('trip_id', $trip['id'])->where('seq', $normalized['origin_seq'])->find();
            $to = Db::table('stops')
                ->where('trip_id', $trip['id'])
                ->where('seq', $normalized['destination_seq'])
                ->find();
            if (!$from || !$to || $normalized['origin_seq'] >= $normalized['destination_seq']) {
                throw new HttpException(422, 'Invalid interval');
            }
            if (
                $from['departure_at'] <=
                (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v')
            ) {
                throw new HttpException(409, 'Departure passed');
            }
            StationDictionary::resolve($from['station_id']);
            StationDictionary::resolve($to['station_id']);
            $s = RollingStock::seat($trip, $seat);
            $quote = FarePricing::quote($from, $to, FarePricing::policy(true), $s['multiplier_bp']);
            if (
                (isset($normalized['stock_version']) && $normalized['stock_version'] !== $version) ||
                (isset($normalized['fare_version']) && $normalized['fare_version'] !== $quote['fare_version'])
            ) {
                throw new HttpException(409, 'Fare/seat policy changed; query again');
            }
            if (
                (new Ticketing())->available($trip, $normalized['origin_seq'], $normalized['destination_seq'], $seat) <
                1
            ) {
                throw new HttpException(409, 'Sold out');
            }
            $row = [
                'id' => bin2hex(random_bytes(16)),
                'agency_code' => $agency,
                'request_key' => $key,
                'body_hash' => $hash,
                'trip_id' => $trip['id'],
                'seat_code' => $seat,
                'origin_seq' => $normalized['origin_seq'],
                'destination_seq' => $normalized['destination_seq'],
                'policy_version' => $version,
                'fare_version' => $quote['fare_version'],
                'status' => 'HELD',
                'expires_at' => min(gmdate('Y-m-d H:i:s', time() + 600), $from['departure_at']),
            ];
            Db::table('inventory_holds')->insert($row);
            return self::present($row);
        });
    }
    public static function present(array $row): array
    {
        if (
            $row['status'] === 'HELD' &&
            $row['expires_at'] <= (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v')
        ) {
            $row['status'] = 'EXPIRED';
        }
        unset($row['body_hash']);
        return $row;
    }
    public static function cancel(string $id, string $agency): array
    {
        return Db::transaction(function () use ($id, $agency) {
            RollingStock::version(true);
            $row = Db::table('inventory_holds')->where('id', $id)->where('agency_code', $agency)->lock(true)->find();
            if (!$row) {
                throw new HttpException(404, 'Reservation not found');
            }
            if ($row['status'] === 'HELD') {
                $row['status'] = 'CANCELLED';
                Db::table('inventory_holds')
                    ->where('id', $id)
                    ->update(['status' => 'CANCELLED']);
            }
            return self::present($row);
        });
    }
}
