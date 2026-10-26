<?php
declare(strict_types=1);
namespace app\command;
use think\console\{Command, Input, Output};
use think\console\input\{Argument, Option};
use think\facade\Db;
use app\service\RollingStock;
/** Backfill stock metadata only from the original import, preserving all commercial records. */
final class MtrStock extends Command
{
    protected function configure()
    {
        $this->setName('mtr:stock')
            ->addArgument('source', Argument::REQUIRED, 'Original dimension directory or ZIP')
            ->addOption('dimension', null, Option::VALUE_REQUIRED);
    }
    protected function execute(Input $in, Output $out)
    {
        $dimension = (string) $in->getOption('dimension');
        if (!preg_match('~^[A-Za-z0-9_/-]{1,120}$~', $dimension)) {
            throw new \InvalidArgumentException('--dimension required');
        }
        $data = (new \app\mtr\WorldReader())->read((string) $in->getArgument('source'));
        $hash = hash('sha256', serialize($data));
        $result = Db::transaction(function () use ($data, $hash, $dimension) {
            RollingStock::version(true);
            $updated = 0;
            $skipped = 0;
            foreach ($data['sidings'] as $s) {
                $code = RollingStock::code($s);
                Db::table('mtr_depot_sidings')
                    ->where('dimension', $dimension)
                    ->where('siding_id', (string) $s['id'])
                    ->update(['stock_code' => $code]);
            }
            $trips = Db::table('trips')->where('dimension', $dimension)->order('id')->lock(true)->select()->toArray();
            $hashes = Db::table('imports')->where('dimension', $dimension)->column('source_hash', 'id');
            foreach ($trips as $t) {
                $s = $data['sidings'][(string) $t['siding_id']] ?? null;
                if (!$s || ($hashes[$t['import_id']] ?? '') !== $hash) {
                    $skipped++;
                    continue;
                }
                $code = RollingStock::code($s);
                if ($t['stock_code'] === $code) {
                    continue;
                }
                if (
                    Db::table('inventory_holds')
                        ->where('trip_id', $t['id'])
                        ->where('status', 'HELD')
                        ->whereRaw('expires_at>UTC_TIMESTAMP(3)')
                        ->count() ||
                    ($t['stock_code'] !== '' && Db::table('coupons')->where('trip_id', $t['id'])->count())
                ) {
                    $skipped++;
                    continue;
                }
                Db::table('trips')
                    ->where('id', $t['id'])
                    ->update(['stock_code' => $code]);
                $updated++;
            }
            return ['updated' => $updated, 'skipped' => $skipped];
        });
        $out->writeln(json_encode($result));
        return $result['skipped'] ? 1 : 0;
    }
}
