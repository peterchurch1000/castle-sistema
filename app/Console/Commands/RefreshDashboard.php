<?php

namespace App\Console\Commands;

use App\Http\Controllers\DashboardController;
use App\Services\DifotService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class RefreshDashboard extends Command
{
    protected $signature = 'castle:refresh {--only= : Limit to one of: sales, quotas, activities, difot, operaciones, pipeline}';

    protected $description = 'Pull fresh dashboard data from NetSuite + Salesforce APIs (sales, quotas, activities, DIFOT, pipeline)';

    public function handle(DashboardController $controller, DifotService $difot): int
    {
        $only = $this->option('only');
        $all = $only === null;
        $ok = true;

        // Sales (NetSuite).
        if ($all || $only === 'sales') {
            $sales = $controller->refresh()->getData(true);
            $this->report('sales', $sales);
            $ok = $ok && ($sales['success'] ?? false);
        }

        // Quotas — loaded from the committed JSON (NetSuite 2026.2 removed the
        // `quota` record from SuiteQL). See App\Console\Commands\SyncQuotas.
        if ($all || $only === 'quotas') {
            $code = Artisan::call('castle:sync-quotas');
            $this->report('quotas', ['success' => $code === 0]);
            $ok = $ok && ($code === 0);
        }

        // Activities (Salesforce).
        if ($all || $only === 'activities') {
            $activities = $controller->refreshActivities()->getData(true);
            $this->report('activities', $activities);
            $ok = $ok && ($activities['success'] ?? false);
        }

        // DIFOT cards (NetSuite) — forced cache warm.
        if ($all || $only === 'difot') {
            $difotRes = $difot->refreshAll();
            $this->report('difot', $difotRes);
            $ok = $ok && ($difotRes['success'] ?? false);
        }

        // Operaciones delivery-split (NetSuite) — forced cache warm.
        if ($all || $only === 'operaciones') {
            $opsRes = $difot->refreshDeliverySplit();
            $this->report('operaciones', $opsRes);
            $ok = $ok && ($opsRes['success'] ?? false);
        }

        // Salesforce pipeline — forced cache warm.
        if ($all || $only === 'pipeline') {
            $res = $controller->refreshPipeline();
            $pipeData = ['success' => ($res['status'] ?? 'failed') === 'ok'];
            $this->report('pipeline', $pipeData);
            $ok = $ok && $pipeData['success'];
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function report(string $label, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $this->line("{$label}: {$json}");
        if ($data['success'] ?? false) {
            Log::info("castle:refresh {$label}", $data);
        } else {
            Log::warning("castle:refresh {$label} failed", $data);
        }
    }
}
