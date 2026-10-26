<?php
declare(strict_types=1);
namespace app\service;
use think\facade\Db;
use think\exception\HttpException;
final class Ticketing
{
    private function fail(string $message, int $code = 422): never
    {
        throw new HttpException($code, $message);
    }
    public function available(array $trip, int $from, int $to): int
    {
        $minimum = (int) $trip['capacity'];
        for ($seq = $from; $seq < $to; $seq++) {
            $used = Db::table('coupons')
                ->where('trip_id', $trip['id'])
                ->where('status', 'OPEN')
                ->where('origin_seq', '<=', $seq)
                ->where('destination_seq', '>', $seq)
                ->count();
            $minimum = min($minimum, max(0, (int) $trip['capacity'] - $used));
        }
        return $minimum;
    }
    public function search(string $origin, string $destination, string $date, array $filters = []): array
    {
        $origin = StationDictionary::resolve($origin);
        $destination = StationDictionary::resolve($destination);
        if ($origin === $destination) {
            $this->fail('Distinct stations required');
        }
        $sql =
            'SELECT t.*, a.seq origin_seq, b.seq destination_seq, a.departure_at, b.arrival_at, (b.distance_m-a.distance_m) distance_m, a.speed_distances_m origin_speed_distances_m, b.speed_distances_m destination_speed_distances_m FROM trips t JOIN stops a ON a.trip_id=t.id JOIN stops b ON b.trip_id=t.id JOIN operators o ON o.code=t.operator_code WHERE t.active=1 AND o.active=1 AND a.station_id=? AND b.station_id=? AND a.seq<b.seq AND a.departure_at>=? AND a.departure_at<?';
        $bindings = [
            $origin,
            $destination,
            $date . ' 00:00:00',
            (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d') . ' 00:00:00',
        ];
        $number = TripFilters::number($filters['train_number'] ?? '');
        [$from, $to] = TripFilters::window($date, $filters['time_from'] ?? '', $filters['time_to'] ?? '');
        if ($number !== null) {
            $sql .= ' AND t.train_number=?';
            $bindings[] = $number;
        }
        if ($from !== null) {
            $sql .= ' AND a.departure_at>=?';
            $bindings[] = $from;
        }
        if ($to !== null) {
            $sql .= ' AND a.departure_at<?';
            $bindings[] = $to;
        }
        $policy = FarePricing::policy();
        $rows = Db::query($sql . ' ORDER BY a.departure_at,t.id,a.seq,b.seq LIMIT 200', $bindings);
        foreach ($rows as &$r) {
            $r = TripIdentity::present($r);
            $r['available'] = $this->available($r, (int) $r['origin_seq'], (int) $r['destination_seq']);
            try {
                $r = array_merge(
                    $r,
                    FarePricing::quote(
                        ['speed_distances_m' => $r['origin_speed_distances_m']],
                        ['speed_distances_m' => $r['destination_speed_distances_m']],
                        $policy,
                    ),
                );
                $r['fare_available'] = true;
            } catch (HttpException $e) {
                $r['amount_minor'] = null;
                $r['fare_available'] = false;
                $r['fare_error'] = $e->getMessage();
            }
            unset($r['origin_speed_distances_m'], $r['destination_speed_distances_m']);
            $r['currency'] = config('ticketing.currency');
        }
        return $rows;
    }
    public function get(string $number, string $agency): array
    {
        $t = Db::table('tickets')->where('ticket_number', $number)->where('agency_code', $agency)->find();
        if (!$t) {
            $this->fail('Ticket not found', 404);
        }
        $t['passenger_document_snapshot'] = $t['passenger_document_snapshot']
            ? json_decode($t['passenger_document_snapshot'], true)
            : null;
        $t['fare_snapshot'] = $t['fare_snapshot'] ? json_decode($t['fare_snapshot'], true) : null;
        $t['coupons'] = Db::table('coupons')->where('ticket_id', $t['id'])->select()->toArray();
        foreach ($t['coupons'] as &$c) {
            $c['trip'] = TripIdentity::present(Db::table('trips')->where('id', $c['trip_id'])->find());
            $c['origin'] = Db::table('stops')->where('trip_id', $c['trip_id'])->where('seq', $c['origin_seq'])->find();
            $c['destination'] = Db::table('stops')
                ->where('trip_id', $c['trip_id'])
                ->where('seq', $c['destination_seq'])
                ->find();
        }
        foreach ($t['coupons'] as &$coupon) {
            $coupon['origin'] = StationDictionary::decorate($coupon['origin']);
            $coupon['destination'] = StationDictionary::decorate($coupon['destination']);
        }
        return $t;
    }
    public function issue(array $body, string $key, string $agency): array
    {
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $key)) {
            $this->fail('Idempotency-Key is required');
        }
        if (!isset($body['trip_id']) || !is_int($body['trip_id']) || $body['trip_id'] < 1) {
            $this->fail('Invalid trip_id');
        }
        $body = StationDictionary::interval($body);
        foreach (['trip_id', 'origin_seq', 'destination_seq'] as $k) {
            if (!isset($body[$k]) || !is_int($body[$k]) || $body[$k] < 0) {
                $this->fail('Expected nonnegative JSON integer: ' . $k);
            }
        }
        if (isset($body['fare_version']) && (!is_int($body['fare_version']) || $body['fare_version'] < 1)) {
            $this->fail('fare_version must be a positive integer');
        }
        $documentId = $body['document_id'] ?? null;
        if (array_key_exists('document_id', $body) && (!is_int($documentId) || $documentId < 1)) {
            $this->fail('document_id must be a positive JSON integer');
        }
        $passenger = '';
        if ($documentId === null) {
            if (!isset($body['passenger']) || !is_string($body['passenger'])) {
                $this->fail('Passenger or document_id is required');
            }
            $passenger = trim($body['passenger']);
            if (!$passenger || mb_strlen($passenger) > 160) {
                $this->fail('Passenger must be 1..160 characters');
            }
        }
        $normalized = [
            'trip_id' => $body['trip_id'],
            'origin_seq' => $body['origin_seq'],
            'destination_seq' => $body['destination_seq'],
            'passenger' => $passenger,
        ];
        if ($documentId !== null) {
            $normalized['document_id'] = $documentId;
        }
        if (isset($body['fare_version'])) {
            $normalized['fare_version'] = $body['fare_version'];
        }
        $hash = hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE));

        try {
            $number = Db::transaction(function () use ($normalized, $key, $agency, $hash) {
                $trip = Db::table('trips')->where('id', $normalized['trip_id'])->lock(true)->find();
                $existing = Db::table('requests')->where('agency_code', $agency)->where('request_key', $key)->find();
                if ($existing) {
                    if (!hash_equals($existing['body_hash'], $hash)) {
                        $this->fail('Idempotency-Key reused with another request', 409);
                    }
                    return Db::table('tickets')->where('id', $existing['ticket_id'])->value('ticket_number');
                }
                if (!$trip || !$trip['active']) {
                    $this->fail('Trip not available', 409);
                }
                $organization = Db::table('agencies')->where('code', $agency)->lock(true)->find();
                if (!$organization || !$organization['active']) {
                    $this->fail('Agency is disabled or unknown', 403);
                }
                $operator = Db::table('operators')->where('code', $trip['operator_code'])->lock(true)->find();
                if (!$operator || !$operator['active']) {
                    $this->fail('Operator is disabled', 409);
                }
                if (
                    $operator['whitelist_enabled'] &&
                    !Db::table('agency_operators')
                        ->where('agency_code', $agency)
                        ->where('operator_code', $trip['operator_code'])
                        ->lock(true)
                        ->find()
                ) {
                    $this->fail('Agency is not authorized for this operator', 403);
                }
                $issuer = $organization['issuer_code'];
                $from = $normalized['origin_seq'];
                $to = $normalized['destination_seq'];
                $origin = Db::table('stops')->where('trip_id', $trip['id'])->where('seq', $from)->find();
                $destination = Db::table('stops')->where('trip_id', $trip['id'])->where('seq', $to)->find();
                if (!$origin || !$destination || $from >= $to) {
                    $this->fail('Invalid station interval');
                }
                StationDictionary::resolve($origin['station_id']);
                StationDictionary::resolve($destination['station_id']);
                if (
                    $origin['departure_at'] <=
                    (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v')
                ) {
                    $this->fail('Departure already passed', 409);
                }
                if ($this->available($trip, $from, $to) < 1) {
                    $this->fail('Sold out', 409);
                }
                $document = null;
                $passenger = $normalized['passenger'];
                if (isset($normalized['document_id'])) {
                    $document = Db::table('passenger_documents')
                        ->where('id', $normalized['document_id'])
                        ->lock(true)
                        ->find();
                    if (!$document) {
                        $this->fail('Passenger document not found', 404);
                    }
                    $document = PassengerDocuments::present($document);
                    $passenger = $document['display_name'];
                }
                $number = $issuer . str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
                $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
                $pnr = '';
                for ($i = 0; $i < 6; $i++) {
                    $pnr .= $alphabet[random_int(0, strlen($alphabet) - 1)];
                }
                $quote = FarePricing::quote($origin, $destination, FarePricing::policy(true));
                if (isset($normalized['fare_version']) && $normalized['fare_version'] !== $quote['fare_version']) {
                    $this->fail('Fare changed; query the interval again', 409);
                }
                $tid = Db::table('tickets')->insertGetId([
                    'ticket_number' => $number,
                    'pnr' => $pnr,
                    'issuer_code' => $issuer,
                    'agency_code' => $agency,
                    'passenger' => $passenger,
                    'passenger_document_id' => $document['id'] ?? null,
                    'passenger_document_snapshot' => $document
                        ? json_encode($document, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                        : null,
                    'fare_snapshot' => json_encode($quote, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'amount_minor' => $quote['amount_minor'],
                    'currency' => config('ticketing.currency'),
                    'status' => 'OPEN',
                ]);
                Db::table('coupons')->insert([
                    'ticket_id' => $tid,
                    'number' => 1,
                    'trip_id' => $trip['id'],
                    'origin_seq' => $from,
                    'destination_seq' => $to,
                    'status' => 'OPEN',
                ]);
                Db::table('requests')->insert([
                    'agency_code' => $agency,
                    'request_key' => $key,
                    'body_hash' => $hash,
                    'ticket_id' => $tid,
                ]);
                Db::table('ticket_events')->insert([
                    'ticket_id' => $tid,
                    'agency_code' => $agency,
                    'action' => 'ISSUE',
                ]);
                return $number;
            });
        } catch (\think\db\exception\PDOException $e) {
            // Unique request keys arbitrate concurrent requests even across different trips.
            $existing = Db::table('requests')->where('agency_code', $agency)->where('request_key', $key)->find();
            if (!$existing) {
                throw $e;
            }
            if (!hash_equals($existing['body_hash'], $hash)) {
                $this->fail('Idempotency-Key reused with another request', 409);
            }
            $number = Db::table('tickets')->where('id', $existing['ticket_id'])->value('ticket_number');
        }
        return $this->get($number, $agency);
    }
    public function refund(string $number, string $agency): array
    {
        $t = $this->get($number, $agency);
        $coupon = $t['coupons'][0];
        Db::transaction(function () use ($t, $coupon, $agency) {
            Db::table('trips')->where('id', $coupon['trip_id'])->lock(true)->find();
            $current = Db::table('tickets')->where('id', $t['id'])->lock(true)->find();
            if (!$current) {
                $this->fail('Ticket not found', 404);
            }
            if ($current['status'] === 'RFND') {
                return;
            }
            $origin = Db::table('stops')
                ->where('trip_id', $coupon['trip_id'])
                ->where('seq', $coupon['origin_seq'])
                ->find();
            if (
                $origin['departure_at'] <=
                (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v')
            ) {
                $this->fail('Cannot refund after departure', 409);
            }
            Db::table('tickets')
                ->where('id', $t['id'])
                ->update(['status' => 'RFND', 'refunded_at' => gmdate('Y-m-d H:i:s')]);
            Db::table('coupons')
                ->where('ticket_id', $t['id'])
                ->update(['status' => 'RFND']);
            Db::table('ticket_events')->insert([
                'ticket_id' => $t['id'],
                'agency_code' => $agency,
                'action' => 'REFUND',
            ]);
        });
        return $this->get($number, $agency);
    }
}
