<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Loads monthly sales targets into sales_quotas from a committed JSON file.
 *
 * Background: NetSuite 2026.2 removed the `quota` record from both SuiteQL and
 * N/search, so DashboardController::refreshQuotas() can no longer mirror targets
 * from NetSuite. Targets still live in NetSuite Quota records (UI only), so we
 * keep a version-controlled copy here and load it with this command. When Peter
 * changes a target in NetSuite, update database/quotas/quotas-<year>.json (read
 * the monthly table at /app/crm/sales/quota.nl?id=<N>) and re-run this command.
 *
 * Rep names in the JSON MUST match sales_data canonical names (see
 * DashboardController::$entityNames), otherwise the dashboard join misses them.
 */
class SyncQuotas extends Command
{
    protected $signature = 'castle:sync-quotas {--year= : Year to load (default: current)} {--file= : Override JSON path}';

    protected $description = 'Load monthly sales quotas into sales_quotas from database/quotas/quotas-<year>.json';

    public function handle(): int
    {
        $year = (int) ($this->option('year') ?: now('America/Argentina/Buenos_Aires')->year);
        $file = $this->option('file') ?: database_path("quotas/quotas-{$year}.json");

        if (! is_file($file)) {
            $this->error("Quota file not found: {$file}");

            return self::FAILURE;
        }

        $doc = json_decode((string) file_get_contents($file), true);
        if (! is_array($doc) || ! isset($doc['quotas'])) {
            $this->error("Invalid quota file (expected {\"year\":..,\"quotas\":{..}}): {$file}");

            return self::FAILURE;
        }

        $quotas = $doc['quotas'];
        $deleted = 0;
        $upserted = 0;

        DB::transaction(function () use ($year, $quotas, &$deleted, &$upserted) {
            $deleted = DB::table('sales_quotas')->where('year', $year)->delete();
            foreach ($quotas as $rep => $months) {
                foreach ($months as $month => $amount) {
                    DB::table('sales_quotas')->updateOrInsert(
                        ['rep_name' => $rep, 'year' => $year, 'month' => (int) $month],
                        ['quota_amount' => (float) $amount, 'created_at' => now(), 'updated_at' => now()]
                    );
                    $upserted++;
                }
            }
        });

        $curMonth = now('America/Argentina/Buenos_Aires')->month;
        $curRows = DB::table('sales_quotas')->where('year', $year)->where('month', $curMonth)->count();

        $this->info("Loaded quotas for {$year}: removed {$deleted} old rows, upserted {$upserted}.");
        $this->info("Current month ({$curMonth}) now has {$curRows} rep(s) with a target.");

        return self::SUCCESS;
    }
}
