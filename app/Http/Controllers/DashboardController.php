<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class DashboardController extends Controller
{
    private string $searchId = 'customsearch2305'; // Castle - MTD Invoices by Sales Rep

    public function index()
    {
        $now = Carbon::now('America/Argentina/Buenos_Aires');
        $year = $now->year;
        $month = $now->month;

        $quotas = DB::table('sales_quotas')
            ->where('year', $year)
            ->where('month', $month)
            ->pluck('quota_amount', 'rep_name');

        $salesData = DB::table('sales_data')
            ->where('year', $year)
            ->where('month', $month)
            ->pluck('sales_amount', 'rep_name');

        $reps = $quotas->keys()->merge($salesData->keys())->unique()->sort()->values();

        $paceRatio = $this->calcPaceRatio($now);

        $chartData = $reps->map(function ($rep) use ($quotas, $salesData, $paceRatio) {
            $quota = (float) ($quotas[$rep] ?? 0);
            $sales = (float) ($salesData[$rep] ?? 0);
            $pace  = $quota * $paceRatio;
            return [
                'rep'   => $rep,
                'sales' => $sales,
                'quota' => $quota,
                'pace'  => $pace,
            ];
        })->sortByDesc('quota')->values();

        $lastRefreshed = DB::table('sales_data')
            ->where('year', $year)->where('month', $month)
            ->max('fetched_at');

        return view('dashboard', compact('chartData', 'lastRefreshed', 'paceRatio', 'month', 'year'));
    }

    public function refresh()
    {
        $data = $this->fetchFromNetsuite();
        if ($data === null) {
            return response()->json(['error' => 'NetSuite fetch failed'], 500);
        }

        $now = Carbon::now('America/Argentina/Buenos_Aires');
        $year = $now->year;
        $month = $now->month;

        DB::table('sales_data')->where('year', $year)->where('month', $month)->delete();

        foreach ($data as $row) {
            DB::table('sales_data')->insert([
                'rep_name'    => $row['rep'],
                'year'        => $year,
                'month'       => $month,
                'sales_amount'=> $row['amount'],
                'fetched_at'  => now(),
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        return response()->json(['success' => true, 'rows' => count($data)]);
    }

    private function fetchFromNetsuite(): ?array
    {
        $conn = $this->buildOauthConnection();

        $bodyContent = json_encode(['searchID' => $this->searchId]);

        $response = Http::withHeaders($this->buildHeaders($conn, $bodyContent))
            ->withBody($bodyContent, 'application/json')
            ->post($conn['url'] . '?script=921&deploy=1');

        if (!$response->successful()) {
            return null;
        }

        $decoded = $response->json();
        if (!isset($decoded['results'])) {
            return null;
        }

        $rows = [];
        foreach ($decoded['results'] as $result) {
            $vals = $result['values'] ?? [];

            // NetSuite wraps grouped/aggregated fields: GROUP(salesrep), SUM(formulacurrency)
            $repField    = $vals['GROUP(salesrep)'] ?? $vals['salesrep'] ?? null;
            $amountField = $vals['SUM(formulacurrency)'] ?? $vals['formulacurrency'] ?? $vals['Sales USD'] ?? 0;

            $rep    = is_array($repField) ? ($repField[0]['text'] ?? '') : ($repField ?? '');
            $amount = (float) $amountField;

            if ($rep && $rep !== '- None -') {
                $rows[] = ['rep' => $rep, 'amount' => $amount];
            }
        }
        return $rows;
    }

    private function buildOauthConnection(): array
    {
        $realm      = env('OAUTH_REALM');
        $nonce      = bin2hex(random_bytes(11));
        $timestamp  = time();
        $consumerKey    = env('OAUTH_RESTLET_CONSUMER_KEY');
        $consumerSecret = env('OAUTH_RESTLET_CONSUMER_SECRET');
        $token      = env('OAUTH_RESTLET_TOKEN');
        $tokenSecret    = env('OAUTH_RESTLET_TOKEN_SECRET');
        $version    = env('OAUTH_VERSION', '1.0');

        $url = "https://{$realm}.restlets.api.netsuite.com/app/site/hosting/restlet.nl";
        $key = rawurlencode($consumerSecret) . '&' . rawurlencode($tokenSecret);

        $paramString = "oauth_consumer_key={$consumerKey}"
            . "&oauth_nonce={$nonce}"
            . "&oauth_signature_method=HMAC-SHA256"
            . "&oauth_timestamp={$timestamp}"
            . "&oauth_token={$token}"
            . "&oauth_version={$version}"
            . "&script=921";

        $baseString = "POST&" . rawurlencode($url) . "&" . rawurlencode("deploy=1&") . rawurlencode($paramString);
        $sig = base64_encode(hash_hmac('sha256', $baseString, $key, true));
        $sig = rawurlencode($sig);

        return compact('url', 'realm', 'consumerKey', 'token', 'sig', 'timestamp', 'nonce', 'version');
    }

    private function buildHeaders(array $c, string $body): array
    {
        return [
            'Prefer'        => 'transient',
            'Content-Type'  => 'application/json',
            'Content-Length'=> strlen($body),
            'Host'          => "{$c['realm']}.restlets.api.netsuite.com",
            'Authorization' => "OAuth realm=\"{$c['realm']}\","
                . "oauth_consumer_key=\"{$c['consumerKey']}\","
                . "oauth_token=\"{$c['token']}\","
                . "oauth_signature_method=\"HMAC-SHA256\","
                . "oauth_timestamp=\"{$c['timestamp']}\","
                . "oauth_nonce=\"{$c['nonce']}\","
                . "oauth_version=\"1.0\","
                . "oauth_signature=\"{$c['sig']}\"",
        ];
    }

    private function calcPaceRatio(Carbon $now): float
    {
        $year  = $now->year;
        $month = $now->month;
        $today = $now->day;

        $holidays = $this->getArgentinaHolidays($year);

        $totalWorkingDays  = 0;
        $elapsedWorkingDays = 0;
        $daysInMonth = $now->daysInMonth;

        for ($d = 1; $d <= $daysInMonth; $d++) {
            $date = Carbon::create($year, $month, $d);
            if ($this->isWorkingDay($date, $holidays)) {
                $totalWorkingDays++;
                if ($d <= $today) {
                    $elapsedWorkingDays++;
                }
            }
        }

        return $totalWorkingDays > 0 ? $elapsedWorkingDays / $totalWorkingDays : 0;
    }

    private function isWorkingDay(Carbon $date, array $holidays): bool
    {
        if ($date->isWeekend()) return false;
        $key = $date->format('Y-m-d');
        return !in_array($key, $holidays);
    }

    private function getArgentinaHolidays(int $year): array
    {
        try {
            $response = Http::timeout(5)->get("https://date.nager.at/api/v3/PublicHolidays/{$year}/AR");
            if ($response->successful()) {
                return array_column($response->json(), 'date');
            }
        } catch (\Exception $e) {
            // fall through to hardcoded list
        }
        return $this->hardcodedHolidays($year);
    }

    private function hardcodedHolidays(int $year): array
    {
        return [
            "{$year}-01-01", "{$year}-02-24", "{$year}-02-25",
            "{$year}-03-24", "{$year}-04-02", "{$year}-04-18",
            "{$year}-05-01", "{$year}-05-25", "{$year}-06-20",
            "{$year}-07-09", "{$year}-08-18", "{$year}-10-12",
            "{$year}-11-20", "{$year}-12-08", "{$year}-12-25",
        ];
    }
}
