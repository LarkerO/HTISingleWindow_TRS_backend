<?php
declare(strict_types=1);
namespace app\service;
use think\facade\Db;
use think\exception\HttpException;
final class StationDictionary
{
    /** MTR separates language variants with |; select the first Han and Latin variants. */
    public static function splitName(string $name): array
    {
        $out = ['name_zh' => '', 'name_en' => ''];
        foreach (explode('|', $name) as $part) {
            $part = trim(preg_replace('/§[0-9a-fk-orx]/iu', '', $part) ?? $part);
            if ($part === '' || mb_strlen($part) > 160) {
                continue;
            }
            if (preg_match('/\\p{Han}/u', $part)) {
                if ($out['name_zh'] === '') {
                    $out['name_zh'] = $part;
                }
            } elseif (
                preg_match('/[A-Za-z]/', $part) &&
                !preg_match('/[\\p{Hiragana}\\p{Katakana}\\p{Hangul}]/u', $part)
            ) {
                if ($out['name_en'] === '') {
                    $out['name_en'] = $part;
                }
            }
        }
        return $out;
    }
    public static function migrate(): void
    {
        // Drafts have no code or operator until configured. Unique NULL codes do not collide.
        Db::execute('ALTER TABLE station_dictionary MODIFY operator_code VARCHAR(16) NULL');
        Db::execute(
            'ALTER TABLE station_dictionary MODIFY telegraph_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL',
        );
        Db::execute(
            "ALTER TABLE station_dictionary ADD COLUMN IF NOT EXISTS name_zh_source VARCHAR(16) NOT NULL DEFAULT 'manual'",
        );
        Db::execute(
            "ALTER TABLE station_dictionary ADD COLUMN IF NOT EXISTS name_en_source VARCHAR(16) NOT NULL DEFAULT 'manual'",
        );
        foreach (Db::table('stations')->column('id') as $id) {
            self::syncName($id);
        }
    }
    public static function syncName(string $id): void
    {
        Db::transaction(function () use ($id) {
            $station = Db::table('stations')->where('id', $id)->lock(true)->find();
            if (!$station) {
                return;
            }
            $names = self::splitName($station['name']);
            $existing = Db::table('station_dictionary')->where('station_id', $id)->lock(true)->find();
            if (!$existing) {
                Db::table('station_dictionary')->insert(
                    [
                        'station_id' => $id,
                        'visible' => 0,
                        'operator_code' => null,
                        'telegraph_code' => null,
                        'name_zh_source' => 'mtr',
                        'name_en_source' => 'mtr',
                    ] + $names,
                );
                return;
            }
            $updates = [];
            foreach ($names as $field => $value) {
                if (($existing[$field . '_source'] ?? 'manual') === 'mtr' || $existing[$field] === '') {
                    $updates[$field] = $value;
                    $updates[$field . '_source'] = 'mtr';
                }
            }
            if ($updates) {
                Db::table('station_dictionary')->where('station_id', $id)->update($updates);
            }
        });
    }
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
                's.*,d.visible,d.operator_code,d.telegraph_code,d.name_zh,d.name_en,d.name_zh_source,d.name_en_source,d.admin_level_1,d.admin_level_2',
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
                $existing = Db::table('station_dictionary')
                    ->where('station_id', $data['station_id'])
                    ->lock(true)
                    ->find();
                foreach (['name_zh', 'name_en'] as $field) {
                    $data[$field . '_source'] =
                        $existing && $existing[$field] === $data[$field]
                            ? $existing[$field . '_source'] ?? 'manual'
                            : 'manual';
                }
                if ($existing) {
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
