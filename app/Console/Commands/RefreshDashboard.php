<?php

namespace App\Console\Commands;

use App\Http\Controllers\DashboardController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshDashboard extends Command
{
    protected $signature = 'castle:refresh {--only= : Limit to "sales" or "activities"}';

    protected $description = 'Pull fresh dashboard data from NetSuite + Salesforce APIs (sales, quotas, activities)';

    public function handle(DashboardController $controller): int
    {
        $only = $this->option('only');
        $ok = true;

        if ($only !== 'activities') {
            $sales = $controller->refresh()->getData(true);
            $this->report('sales', $sales);
            $ok = $ok && ($sales['success'] ?? false);

            $quotas = $controller->refreshQuotas()->getData(true);
            $this->report('quotas', $quotas);
            $ok = $ok && ($quotas['success'] ?? false);
        }

        if ($only !== 'sales') {
            $activities = $controller->refreshActivities()->getData(true);
            $this->report('activities', $activities);
            $ok = $ok && ($activities['success'] ?? false);
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
