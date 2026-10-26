<?php
declare(strict_types=1);
namespace app\service;
use think\facade\Db;
use think\exception\HttpException;
final class StationDictionary
{
    public static function listing(bool $admin, string $search = '', string $dimension = '', int $page = 1): array
    {
        $q = Db::table('stations')->alias('s')->leftJoin('station_dictionary d', 'd.station_id=s.id');
        if (!$admin) {
            $q->where('d.visible', 1);
        }
        if ($dimension !== '') {
            $q->where('s.dimension', $dimension);
        }
        if ($search !== '') {
            $term = '%' . mb_substr($search, 0, 100) . '%';
            $q->whereRaw(
                '(s.name LIKE ? OR d.name_zh LIKE ? OR d.name_en LIKE ? OR CONVERT(d.telegraph_code USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?)',
                [$term, $term, $term, $term],
            );
        }
        $total = (clone $q)->count();
        $rows = $q
            ->field(
                's.*,d.visible,d.operator_code,d.telegraph_code,d.name_zh,d.name_en,d.admin_level_1,d.admin_level_2',
            )
            ->order('s.name,s.id')
            ->page(max(1, $page), 500)
            ->select()
            ->toArray();
        foreach ($rows as &$row) {
            $row['visible'] = (bool) $row['visible'];
            $row['source_name'] = $row['name'];
            $row['name'] = $row['name_zh'] ?: $row['name'];
        }
        return ['data' => $rows, 'total' => $total, 'page' => max(1, $page), 'limit' => 500];
    }
    public static function save(array $body): array
    {
        $data = [];
        foreach (
            [
                'station_id' => 160,
                'operator_code' => 16,
                'telegraph_code' => 3,
                'name_zh' => 160,
                'name_en' => 160,
                'admin_level_1' => 160,
                'admin_level_2' => 160,
            ]
            as $key => $max
        ) {
            if (!isset($body[$key]) || !is_string($body[$key]) || mb_strlen(trim($body[$key])) > $max) {
                throw new HttpException(422, 'Invalid ' . $key);
            }
            $data[$key] = trim($body[$key]);
        }
        $data['telegraph_code'] = strtoupper($data['telegraph_code']);
        if (
            !preg_match('/^[A-Z]{3}$/D', $data['telegraph_code']) ||
            $data['name_zh'] === '' ||
            $data['name_en'] === '' ||
            !isset($body['visible']) ||
            !is_bool($body['visible'])
        ) {
            throw new HttpException(422, 'Three-letter telegraph_code, names and boolean visible required');
        }
        $data['visible'] = (int) $body['visible'];
        if (!Db::table('stations')->where('id', $data['station_id'])->find()) {
            throw new HttpException(404, 'Station not found');
        }
        if (!Db::table('operators')->where('code', $data['operator_code'])->find()) {
            throw new HttpException(422, 'Unknown operator_code');
        }
        try {
            Db::transaction(function () use ($data) {
                Db::table('stations')->where('id', $data['station_id'])->lock(true)->find();
                if (Db::table('station_dictionary')->where('station_id', $data['station_id'])->find()) {
                    Db::table('station_dictionary')->where('station_id', $data['station_id'])->update($data);
                } else {
                    Db::table('station_dictionary')->insert($data);
                }
            });
        } catch (\think\db\exception\PDOException $e) {
            if (
                Db::table('station_dictionary')
                    ->where('telegraph_code', $data['telegraph_code'])
                    ->where('station_id', '<>', $data['station_id'])
                    ->find()
            ) {
                throw new HttpException(409, 'Telegraph code already assigned');
            }
            throw $e;
        }
        $data['visible'] = (bool) $data['visible'];
        return $data;
    }
    public static function resolve(string $reference, bool $visibleOnly = true): string
    {
        $q = Db::table('station_dictionary');
        if ($visibleOnly) {
            $q->where('visible', 1);
        }
        if (preg_match('/^[a-zA-Z]{3}$/D', trim($reference))) {
            $q->where('telegraph_code', strtoupper(trim($reference)));
        } else {
            $q->where('station_id', $reference);
        }
        $id = $q->value('station_id');
        if (!$id) {
            throw new HttpException(422, 'Station is unknown or hidden');
        }
        return $id;
    }
    public static function decorate(?array $stop): ?array
    {
        if (!$stop) {
            return null;
        }
        return $stop + ['station' => Db::table('station_dictionary')->where('station_id', $stop['station_id'])->find()];
    }
    public static function interval(array $body): array
    {
        foreach (['origin', 'destination'] as $key) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            if (!is_string($body[$key])) {
                throw new HttpException(422, $key . ' must be a station ID or telegraph code');
            }
            $id = self::resolve($body[$key], false);
            $q = Db::table('stops')->where('trip_id', $body['trip_id'])->where('station_id', $id);
            if (isset($body[$key . '_seq'])) {
                if (!is_int($body[$key . '_seq']) || $body[$key . '_seq'] < 0) {
                    throw new HttpException(422, 'Invalid station sequence');
                }
                $q->where('seq', $body[$key . '_seq']);
            }
            $matches = $q->column('seq');
            if (count($matches) !== 1) {
                throw new HttpException(422, 'Station missing or ambiguous; provide matching ' . $key . '_seq');
            }
            $body[$key . '_seq'] = (int) $matches[0];
        }
        return $body;
    }
}
