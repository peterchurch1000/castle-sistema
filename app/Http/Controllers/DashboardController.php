<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    private string $searchId = 'customsearch2305'; // Castle - MTD Invoices by Sales Rep

    // NetSuite entity ID → canonical rep name
    private array $entityNames = [
        742   => 'Barry Latimer',
        1464  => 'Cecilia Berdini',
        1465  => 'Magdalena Guerra',
        2013  => 'Sebastián Sanchez',
        6668  => 'Aylen Pino',
        8502  => 'Leandro Coviello',
        43137 => 'Alexa Pieroni',
        69678 => 'Andrés Capiglioni',
        5440  => 'Bianca James',
    ];

    // Name aliases: NetSuite/Salesforce variant → canonical
    private array $nameAliases = [
        'Cecilia I Berdini' => 'Cecilia Berdini',
        'SebastiÃ¡n Sanchez' => 'Sebastián Sanchez',
        'AndrÃ©s Capiglioni' => 'Andrés Capiglioni',
    ];

    private function normalizeName(string $name): string
    {
        return $this->nameAliases[$name] ?? $name;
    }

    private array $monthNames = [
        1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',
        7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December',
    ];

    public function index()
    {
        $now   = Carbon::now('America/Argentina/Buenos_Aires');
        $year  = $now->year;
        $month = $now->month;

        $paceRatio = $this->calcPaceRatio($now);

        // --- Sales ---
        $quotas = DB::table('sales_quotas')
            ->where('year', $year)->where('month', $month)
            ->pluck('quota_amount', 'rep_name');

        $salesData = DB::table('sales_data')
            ->where('year', $year)->where('month', $month)
            ->pluck('sales_amount', 'rep_name');

        $soldData = DB::table('sales_data')
            ->where('year', $year)->where('month', $month)
            ->pluck('sold_amount', 'rep_name');

        $soldNextData = DB::table('sales_data')
            ->where('year', $year)->where('month', $month)
            ->pluck('sold_amount_next', 'rep_name');

        $pendingData = DB::table('sales_data')
            ->where('year', $year)->where('month', $month)
            ->pluck('pending_billing_amount', 'rep_name');

        // --- Activities ---
        $activityData = DB::table('activity_data')
            ->where('year', $year)->where('month', $month)
            ->get()->keyBy('rep_name');

        $activityQuotas = DB::table('activity_quotas')
            ->where('year', $year)->where('month', $month)
            ->get()->keyBy('rep_name');

        // Unified rep list from all four sources, same order for both charts
        $excludeReps = ['Equipo AT Vault', 'Roy James', 'Luciano Tabares', 'Cesar Cusit', 'Gianni Pollard', 'Sandra Berdini'];
        $allReps = $quotas->keys()
            ->merge($salesData->keys())
            ->merge($activityData->keys())
            ->merge($activityQuotas->keys())
            ->unique()
            ->reject(fn($r) => in_array($r, $excludeReps))
            ->values();

        // Sort: activity target desc, then sales quota desc, then name asc
        $repsArr = $allReps->all();
        usort($repsArr, function ($a, $b) use ($quotas, $activityQuotas) {
            $aAct = $activityQuotas->has($a) ? (int) $activityQuotas->get($a)->weighted_target : 0;
            $bAct = $activityQuotas->has($b) ? (int) $activityQuotas->get($b)->weighted_target : 0;
            if ($aAct !== $bAct) return $bAct <=> $aAct;
            $aSales = (float) ($quotas[$a] ?? 0);
            $bSales = (float) ($quotas[$b] ?? 0);
            if ($aSales !== $bSales) return $bSales <=> $aSales;
            return $a <=> $b;
        });
        $allReps = collect($repsArr);

        $chartData = $allReps->map(function ($rep) use ($quotas, $salesData, $soldData, $soldNextData, $pendingData, $paceRatio) {
            $quota    = (float) ($quotas[$rep] ?? 0);
            $sales    = (float) ($salesData[$rep] ?? 0);
            $sold     = (float) ($soldData[$rep] ?? 0);
            $soldNext = (float) ($soldNextData[$rep] ?? 0);
            $pending  = (float) ($pendingData[$rep] ?? 0);
            return [
                'rep'      => $rep,
                'sales'    => $sales,
                'sold'     => $sold,
                'soldNext' => $soldNext,
                'pending'  => $pending,
                'quota'   => $quota,
                'pace'    => $quota * $paceRatio,
            ];
        })->values();

        $activityChartData = $allReps->map(function ($rep) use ($activityData, $activityQuotas, $paceRatio) {
            $act    = $activityData->get($rep);
            $tgt    = $activityQuotas->get($rep);
            $score  = $act ? (int) $act->weighted_score : 0;
            $target = $tgt ? (int) $tgt->weighted_target : 0;
            return [
                'rep'    => $rep,
                'score'  => $score,
                'target' => $target,
                'pace'   => $target * $paceRatio,
                'emails'   => $act ? (int) $act->emails   : 0,
                'calls'    => $act ? (int) $act->calls    : 0,
                'visits'   => $act ? (int) $act->visits   : 0,
                'whatsapp' => $act ? (int) $act->whatsapp : 0,
                'linkedin' => $act ? (int) $act->linkedin : 0,
            ];
        })->values();

        // Reps that count toward the header-card totals but are hidden from the
        // per-rep charts: Bianca's invoiced sales roll up into the totals but she
        // is not drawn as a bar; same for the dashboard owner.
        $chartHidden   = ['Bianca James', 'Peter Church'];
        $salesChart    = $chartData->reject(fn($d) => in_array($d['rep'], $chartHidden))->values();
        $activityChart = $activityChartData->reject(fn($d) => in_array($d['rep'], $chartHidden))->values();

        $lastRefreshed = DB::table('sales_data')
            ->where('year', $year)->where('month', $month)
            ->max('fetched_at');

        $activityLastRefreshed = DB::table('activity_data')
            ->where('year', $year)->where('month', $month)
            ->max('fetched_at');

        $quotaLastRefreshed = DB::table('sales_quotas')
            ->where('year', $year)->where('month', $month)
            ->max('updated_at');

        // GP % per card (saved search 422 methodology), keyed by metric
        $gp = DB::table('sales_gp')
            ->where('year', $year)->where('month', $month)
            ->pluck('gp_pct', 'metric');

        $pipeline = $this->fetchPipeline();

        return view('dashboard', compact(
            'chartData', 'lastRefreshed', 'paceRatio', 'month', 'year',
            'activityChartData', 'activityLastRefreshed', 'quotaLastRefreshed', 'gp', 'pipeline',
            'salesChart', 'activityChart'
        ));
    }

    public function refresh()
    {
        $now   = Carbon::now('America/Argentina/Buenos_Aires');
        $year  = $now->year;
        $month = $now->month;

        // Invoiced (facturado) per rep — reproduces NetSuite report cr=270
        // "Sales by Sales Rep Summary" (USD, current month). Returns null on failure.
        $invoiced = $this->fetchInvoiced($year, $month);
        if ($invoiced === null) {
            return response()->json(['error' => 'NetSuite fetch failed'], 500);
        }

        // Zona gris: sold-but-not-invoiced per rep (open sales orders this month)
        $sold = $this->fetchSoldNotInvoiced($year, $month);

        // Zona gris del próximo mes: open orders whose ship date falls *within* next
        // month — current month closed out (lower bound = first day of next month).
        $next     = $now->copy()->addMonthNoOverflow();
        $nextFrom = $next->copy()->startOfMonth()->format('d/m/Y');
        $soldNext = $this->fetchSoldNotInvoiced($next->year, $next->month, $nextFrom);

        // Remito por facturar: delivered but not yet invoiced (NetSuite saved search 810).
        // Full outstanding backlog regardless of date.
        $pending = $this->fetchPendingBilling();

        // Merge rep lists so a rep that appears in any one source still gets a row
        $allReps = array_unique(array_merge(
            array_keys($invoiced), array_keys($sold), array_keys($soldNext), array_keys($pending)
        ));

        DB::transaction(function () use ($allReps, $invoiced, $sold, $soldNext, $pending, $year, $month) {
            DB::table('sales_data')->where('year', $year)->where('month', $month)->delete();
            foreach ($allReps as $rep) {
                DB::table('sales_data')->insert([
                    'rep_name'               => $rep,
                    'year'                   => $year,
                    'month'                  => $month,
                    'sales_amount'           => $invoiced[$rep] ?? 0,
                    'sold_amount'            => $sold[$rep] ?? 0,
                    'sold_amount_next'       => $soldNext[$rep] ?? 0,
                    'pending_billing_amount' => $pending[$rep] ?? 0,
                    'fetched_at'             => now(),
                    'created_at'             => now(),
                    'updated_at'             => now(),
                ]);
            }
        });

        // GP % per card population (saved search 422 methodology)
        $gp = $this->fetchGpPercents($year, $month);
        DB::transaction(function () use ($gp, $year, $month) {
            DB::table('sales_gp')->where('year', $year)->where('month', $month)->delete();
            foreach ($gp as $metric => $pct) {
                DB::table('sales_gp')->insert([
                    'year'       => $year,
                    'month'      => $month,
                    'metric'     => $metric,
                    'gp_pct'     => $pct,
                    'fetched_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return response()->json([
            'success'  => true,
            'rows'     => count($allReps),
            'invoiced' => count($invoiced),
            'sold'     => count($sold),
            'pending'  => count($pending),
            'gp'       => $gp,
        ]);
    }

    /**
     * GP % per card population, reproducing saved search 422 ("GP por Grupo de
     * Productos facturación"). The report's "% GP APROX" is the AVG of each line's
     * estimated GP% (estgrossprofitpct in the saved search == transactionline
     * .estgrossprofitpercent in SuiteQL) with its 50% fallback caps: sample items,
     * zero estimated cost, a 100%/0% margin, or a margin below -100% all read 50.
     * Each card uses the same population that produces its dollar figure:
     *   invoiced  → Ventas MTD (invoices + credit memos, report criteria)
     *   sold      → Vendido no facturado (open SO lines, ship date ≤ end of month)
     *   sold_next → Vendido no facturado próximo mes (ship date within next month only)
     *   pending   → Remito por facturar (SO lines pending billing)
     *   total     → Total General (AVG over the union of the three line sets)
     * Returns [metric => float|null]; null when the SuiteQL call fails.
     */
    private function fetchGpPercents(int $year, int $month): array
    {
        $gp = "ROUND(CASE WHEN t.custbodycustom_es_muestra='T' THEN 50 "
            . "WHEN tl.costestimate = 0 THEN 50 "
            . "WHEN tl.estgrossprofitpercent IN (1,0) THEN 50 "
            . "WHEN tl.estgrossprofitpercent < -1 THEN 50 "
            . "ELSE tl.estgrossprofitpercent*100 END, 2)";
        $usd = "(SELECT id FROM currency WHERE symbol='USD')";

        $first  = Carbon::create($year, $month, 1);
        $som    = $first->format('d/m/Y');
        $eom    = $first->copy()->endOfMonth()->format('d/m/Y');
        $eomNxt = $first->copy()->addMonthNoOverflow()->endOfMonth()->format('d/m/Y');
        $somNxt = $first->copy()->addMonthNoOverflow()->startOfMonth()->format('d/m/Y');

        $invoiced = "FROM transactionline tl JOIN transaction t ON t.id=tl.transaction "
            . "WHERE t.type IN ('CustInvc','CustCred') AND tl.mainline='F' AND tl.taxline='F' "
            . "AND tl.item NOT IN (8573,13,8718,-3,-6) "
            . "AND t.trandate BETWEEN TO_DATE('{$som}','DD/MM/YYYY') AND TO_DATE('{$eom}','DD/MM/YYYY')";

        $sold = fn (string $d, ?string $from = null) => "FROM transactionline tl JOIN transaction t ON t.id=tl.transaction "
            . "WHERE t.type='SalesOrd' AND t.currency={$usd} AND tl.mainline='F' AND tl.taxline='F' "
            . ($from !== null ? "AND tl.custcol_3k_fecha_envio_cumplimiento >= TO_DATE('{$from}','DD/MM/YYYY') " : '')
            . "AND tl.custcol_3k_fecha_envio_cumplimiento <= TO_DATE('{$d}','DD/MM/YYYY') "
            . "AND (ABS(tl.quantity)-NVL(tl.quantityshiprecv,0))>0 AND t.status NOT IN ('C','G','H')";

        $pending = "FROM transactionline tl JOIN transaction t ON t.id=tl.transaction "
            . "WHERE t.type='SalesOrd' AND tl.mainline='F' AND tl.taxline='F' AND t.status IN ('E','F') "
            . "AND t.currency={$usd} AND tl.quantityshiprecv>0 AND t.tranid<>'29964'";

        $queries = [
            'invoiced'  => "SELECT AVG({$gp}) AS gp {$invoiced}",
            'sold'      => "SELECT AVG({$gp}) AS gp " . $sold($eom),
            'sold_next' => "SELECT AVG({$gp}) AS gp " . $sold($eomNxt, $somNxt),
            'pending'   => "SELECT AVG({$gp}) AS gp {$pending}",
            'total'     => "SELECT AVG(g) AS gp FROM ("
                . "SELECT {$gp} AS g {$invoiced} UNION ALL "
                . "SELECT {$gp} AS g " . $sold($eom) . " UNION ALL "
                . "SELECT {$gp} AS g {$pending})",
        ];

        $out = [];
        foreach ($queries as $metric => $sql) {
            $rows = $this->suiteqlQuery($sql);
            $out[$metric] = ($rows && isset($rows[0]['gp']) && $rows[0]['gp'] !== null)
                ? round((float) $rows[0]['gp'], 2)
                : null;
        }

        return $out;
    }

    public function refreshActivities()
    {
        $token = $this->getSalesforceToken();
        if (!$token) {
            return response()->json([
                'error' => 'Salesforce auth failed. Check SF_CLIENT_ID/SF_CLIENT_SECRET and ensure a Run As user is assigned in the app\'s OAuth Policies.'
            ], 500);
        }

        $now      = Carbon::now('America/Argentina/Buenos_Aires');
        $year     = $now->year;
        $month    = $now->month;
        $firstDay    = $now->copy()->startOfMonth()->format('Y-m-d');
        $lastDay     = $now->copy()->endOfMonth()->format('Y-m-d');
        $firstDayDT  = $now->copy()->startOfMonth()->utc()->format('Y-m-d\TH:i:s\Z');
        $lastDayDT   = $now->copy()->endOfMonth()->utc()->format('Y-m-d\TH:i:s\Z');

        // Outbound emails (EAC captures Gmail automatically)
        $emailRecs = $this->sfQuery($token,
            "SELECT CreatedBy.Name, COUNT(Id) emailCount FROM EmailMessage " .
            "WHERE Incoming = false " .
            "AND MessageDate >= {$firstDayDT} AND MessageDate <= {$lastDayDT} " .
            "GROUP BY CreatedBy.Name"
        );

        // Calls, meetings, WhatsApp and LinkedIn are all completed Task records that
        // differ only by Subject prefix. Subject is not groupable in SOQL, so they are
        // fetched in one flat query and counted per rep by prefix in PHP below — replacing
        // four near-identical SOQL calls with one (sfQuery pages through large results).
        //   'R - LLAMADA%'           → calls    (excl. pending 'T - LLAMAR')
        //   'R - MINUTA DE REUNION%' → visits   (presencial + virtual)
        //   'R - WA%'                → whatsapp
        //   'R - LINKEDIN%'          → linkedin
        $taskRecs = $this->sfQuery($token,
            "SELECT Owner.Name, Subject FROM Task " .
            "WHERE (Subject LIKE 'R - LLAMADA%' OR Subject LIKE 'R - MINUTA DE REUNION%' " .
            "OR Subject LIKE 'R - WA%' OR Subject LIKE 'R - LINKEDIN%') " .
            "AND ActivityDate >= {$firstDay} AND ActivityDate <= {$lastDay}"
        );

        if ($emailRecs === null || $taskRecs === null) {
            return response()->json(['error' => 'Salesforce SOQL query failed — check logs'], 500);
        }

        // Activity_Target__c quotas for the current year (month matched in PHP).
        // Objectives change ~monthly, so the result is cached for 6h to avoid querying
        // Salesforce on every activity refresh. Empty/failed results are not cached, and
        // clearing cache key "sf_quota_{$year}" forces an immediate reload.
        $quotaRecs = Cache::get("sf_quota_{$year}");
        if ($quotaRecs === null) {
            $quotaRecs = $this->sfQuery($token,
                "SELECT User__r.Name, Month__c, Year__c, " .
                "Calls_Target__c, Emails_Target__c, Visits_Target__c " .
                "FROM Activity_Target__c WHERE Year__c = {$year}"
            );
            if (!empty($quotaRecs)) {
                Cache::put("sf_quota_{$year}", $quotaRecs, now()->addHours(6));
            }
        }

        // Build activity map keyed by rep name
        // Aggregate GROUP BY relationship fields flatten to the last segment (e.g. CreatedBy.Name → Name)
        $map = [];
        foreach ($emailRecs as $r) {
            $rep = $r['Name'] ?? $r['CreatedBy']['Name'] ?? null;
            if ($rep) $map[$rep]['emails'] = (int) ($r['emailCount'] ?? $r['expr0'] ?? 0);
        }
        foreach ($taskRecs as $r) {
            $rep = $r['Name'] ?? $r['Owner']['Name'] ?? null;
            if (!$rep) continue;
            $subj = $r['Subject'] ?? '';
            if (str_starts_with($subj, 'R - LLAMADA')) {
                $map[$rep]['calls'] = ($map[$rep]['calls'] ?? 0) + 1;
            } elseif (str_starts_with($subj, 'R - MINUTA DE REUNION')) {
                $map[$rep]['visits'] = ($map[$rep]['visits'] ?? 0) + 1;
            } elseif (str_starts_with($subj, 'R - WA')) {
                $map[$rep]['whatsapp'] = ($map[$rep]['whatsapp'] ?? 0) + 1;
            } elseif (str_starts_with($subj, 'R - LINKEDIN')) {
                $map[$rep]['linkedin'] = ($map[$rep]['linkedin'] ?? 0) + 1;
            }
        }

        // Build quota map — handle picklist month as name or number
        $monthStr = $this->monthNames[$month];
        $quotaMap = [];
        foreach (($quotaRecs ?? []) as $r) {
            $m = $r['Month__c'] ?? '';
            $matches = $m == $month
                || $m === (string) $month
                || strtolower($m) === strtolower($monthStr);
            if (!$matches) continue;
            $rep = $r['User__r']['Name'] ?? null;
            if (!$rep) continue;
            $e = (int) ($r['Emails_Target__c'] ?? 0);
            $c = (int) ($r['Calls_Target__c']  ?? 0);
            $v = (int) ($r['Visits_Target__c'] ?? 0);
            $quotaMap[$rep] = [
                'emails_target'   => $e,
                'calls_target'    => $c,
                'visits_target'   => $v,
                'weighted_target' => $e * 1 + $c * 3 + $v * 6,
            ];
        }

        // Persist activity_data
        DB::transaction(function () use ($map, $year, $month) {
            DB::table('activity_data')->where('year', $year)->where('month', $month)->delete();
            foreach ($map as $rep => $acts) {
                $mail = $acts['emails']   ?? 0;
                $wa   = $acts['whatsapp'] ?? 0;
                $li   = $acts['linkedin'] ?? 0;
                $c    = $acts['calls']    ?? 0;
                $v    = $acts['visits']   ?? 0;
                // Scoring weights: email ×1, LinkedIn ×1, WhatsApp ×3 (a WhatsApp touch
                // is worth three points, not one like an email). `emails` keeps the
                // combined touch count for the breakdown display; whatsapp/linkedin
                // columns keep the component split for transparency.
                $emails = $mail + $wa + $li;
                DB::table('activity_data')->insert([
                    'rep_name'       => $rep,
                    'year'           => $year,
                    'month'          => $month,
                    'emails'         => $emails,
                    'calls'          => $c,
                    'visits'         => $v,
                    'whatsapp'       => $wa,
                    'linkedin'       => $li,
                    'weighted_score' => $mail * 1 + $li * 1 + $wa * 3 + $c * 3 + $v * 6,
                    'fetched_at'     => now(),
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }
        });

        // Persist activity_quotas
        if ($quotaMap) {
            DB::transaction(function () use ($quotaMap, $year, $month) {
                DB::table('activity_quotas')->where('year', $year)->where('month', $month)->delete();
                foreach ($quotaMap as $rep => $q) {
                    DB::table('activity_quotas')->insert([
                        'rep_name'        => $rep,
                        'year'            => $year,
                        'month'           => $month,
                        'emails_target'   => $q['emails_target'],
                        'calls_target'    => $q['calls_target'],
                        'visits_target'   => $q['visits_target'],
                        'weighted_target' => $q['weighted_target'],
                        'created_at'      => now(),
                        'updated_at'      => now(),
                    ]);
                }
            });
        }

        return response()->json([
            'success' => true,
            'reps'    => count($map),
            'quotas'  => count($quotaMap),
        ]);
    }

    public function refreshQuotas()
    {
        $now   = Carbon::now('America/Argentina/Buenos_Aires');
        $year  = $now->year;
        $month = $now->month;

        // Fetch all year rows; filter by month in PHP (quota.date range filter unsupported in SuiteQL)
        $rows = $this->suiteqlQuery(
            "SELECT entity, date, mamount FROM quota " .
            "WHERE year = {$year} AND ismanager = 'F'"
        );

        if ($rows === null) {
            return response()->json(['error' => 'NetSuite SuiteQL quota fetch failed — check logs'], 500);
        }

        // quota.date is DD/MM/YYYY — extract month from position 3-4
        $monthRows = array_filter($rows, function ($row) use ($month) {
            $m = (int) substr($row['date'] ?? '', 3, 2);
            return $m === $month;
        });

        $imported = 0;
        DB::transaction(function () use ($monthRows, $year, $month, &$imported) {
            DB::table('sales_quotas')->where('year', $year)->where('month', $month)->delete();
            foreach ($monthRows as $row) {
                $entityId = (int) $row['entity'];
                $repName  = $this->entityNames[$entityId] ?? null;
                if (!$repName) {
                    Log::warning('NS quota: unknown entity ID', ['entity' => $entityId]);
                    continue;
                }
                DB::table('sales_quotas')->insert([
                    'rep_name'     => $repName,
                    'year'         => $year,
                    'month'        => $month,
                    'quota_amount' => (float) $row['mamount'],
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
                $imported++;
            }
        });

        return response()->json(['success' => true, 'imported' => $imported, 'year' => $year, 'month' => $month]);
    }

    private function suiteqlQuery(string $sql): ?array
    {
        $realm         = config('integrations.netsuite.realm');
        $consumerKey   = config('integrations.netsuite.suiteql.consumer_key');
        $consumerSecret= config('integrations.netsuite.suiteql.consumer_secret');
        $token         = config('integrations.netsuite.suiteql.token');
        $tokenSecret   = config('integrations.netsuite.suiteql.token_secret');

        $baseUrl = "https://{$realm}.suitetalk.api.netsuite.com/services/rest/query/v1/suiteql";
        $nonce   = bin2hex(random_bytes(11));
        $ts      = time();
        $key     = rawurlencode($consumerSecret) . '&' . rawurlencode($tokenSecret);

        $allParams = [
            'limit'                  => 200,
            'oauth_consumer_key'     => $consumerKey,
            'oauth_nonce'            => $nonce,
            'oauth_signature_method' => 'HMAC-SHA256',
            'oauth_timestamp'        => $ts,
            'oauth_token'            => $token,
            'oauth_version'          => '1.0',
            'offset'                 => 0,
        ];
        ksort($allParams);
        $paramStr = http_build_query($allParams, '', '&', PHP_QUERY_RFC3986);
        $baseStr  = 'POST&' . rawurlencode($baseUrl) . '&' . rawurlencode($paramStr);
        $sig      = rawurlencode(base64_encode(hash_hmac('sha256', $baseStr, $key, true)));

        $auth = "OAuth realm=\"{$realm}\","
            . "oauth_consumer_key=\"{$consumerKey}\","
            . "oauth_token=\"{$token}\","
            . "oauth_signature_method=\"HMAC-SHA256\","
            . "oauth_timestamp=\"{$ts}\","
            . "oauth_nonce=\"{$nonce}\","
            . "oauth_version=\"1.0\","
            . "oauth_signature=\"{$sig}\"";

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization'   => $auth,
                    'Content-Type'    => 'application/json',
                    'Prefer'          => 'transient',
                    'Accept-Language' => 'es',
                ])
                ->post($baseUrl . '?limit=200&offset=0', ['q' => $sql]);

            if (!$response->successful()) {
                Log::error('SuiteQL error', ['sql' => substr($sql, 0, 100), 'body' => $response->body()]);
                return null;
            }
            return $response->json('items') ?? [];
        } catch (\Exception $e) {
            Log::error('SuiteQL exception', ['msg' => $e->getMessage()]);
            return null;
        }
    }

    private function fetchPipeline(): ?array
    {
        return Cache::store('file')->remember('sf_pipeline_v1', 600, function () {
            $token = $this->getSalesforceToken();
            if (!$token) return null;

            $now   = Carbon::now('America/Argentina/Buenos_Aires');
            $first = $now->copy()->startOfMonth()->format('Y-m-d');
            $last  = $now->copy()->endOfMonth()->format('Y-m-d');

            $mes = $this->sfQuery($token,
                "SELECT COUNT(Id) cnt, SUM(Amount) amt FROM Opportunity " .
                "WHERE IsClosed = false AND CloseDate >= {$first} AND CloseDate <= {$last}");
            $tot = $this->sfQuery($token,
                "SELECT COUNT(Id) cnt, SUM(Amount) amt FROM Opportunity WHERE IsClosed = false");

            if ($mes === null || $tot === null) return null;

            return [
                'previstos' => ['amt' => (float) ($mes[0]['amt'] ?? 0), 'cnt' => (int) ($mes[0]['cnt'] ?? 0)],
                'pipeline'  => ['amt' => (float) ($tot[0]['amt'] ?? 0), 'cnt' => (int) ($tot[0]['cnt'] ?? 0)],
            ];
        });
    }

    private function getSalesforceToken(): ?string
    {
        // Cache the client-credentials token (~50 min; the SF session lasts longer) so
        // each refresh reuses it instead of re-authenticating. Failures are not cached,
        // and sfQuery() busts this key on a 401 so a stale token self-heals next run.
        $cached = Cache::get('sf_access_token');
        if ($cached) {
            return $cached;
        }
        try {
            $response = Http::timeout(15)->asForm()->post(
                config('integrations.salesforce.instance_url') . '/services/oauth2/token',
                [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => config('integrations.salesforce.client_id'),
                    'client_secret' => config('integrations.salesforce.client_secret'),
                ]
            );
            if (!$response->successful()) {
                Log::error('SF token error', ['body' => $response->body()]);
                return null;
            }
            $token = $response->json('access_token');
            if ($token) {
                Cache::put('sf_access_token', $token, now()->addMinutes(50));
            }
            return $token;
        } catch (\Exception $e) {
            Log::error('SF token exception', ['msg' => $e->getMessage()]);
            return null;
        }
    }

    private function sfQuery(string $token, string $soql): ?array
    {
        try {
            $base = config('integrations.salesforce.instance_url');
            $response = Http::timeout(30)
                ->withToken($token)
                ->get($base . '/services/data/v62.0/query', ['q' => $soql]);
            if (!$response->successful()) {
                if ($response->status() === 401) {
                    Cache::forget('sf_access_token');
                }
                Log::error('SF SOQL error', ['soql' => substr($soql, 0, 100), 'body' => $response->body()]);
                return null;
            }
            $records = $response->json('records') ?? [];
            // Follow pagination so large result sets (e.g. a full month of Task rows)
            // come back complete instead of truncated at the 2000-row first page.
            while ($response->json('done') === false && $response->json('nextRecordsUrl')) {
                $response = Http::timeout(30)
                    ->withToken($token)
                    ->get($base . $response->json('nextRecordsUrl'));
                if (!$response->successful()) {
                    Log::error('SF SOQL paging error', ['body' => $response->body()]);
                    return null;
                }
                $records = array_merge($records, $response->json('records') ?? []);
            }
            return $records;
        } catch (\Exception $e) {
            Log::error('SF SOQL exception', ['msg' => $e->getMessage()]);
            return null;
        }
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
            $rep    = $this->normalizeName($rep);
            $amount = (float) $amountField;

            if ($rep && $rep !== '- None -') {
                $rows[] = ['rep' => $rep, 'amount' => $amount];
            }
        }
        return $rows;
    }

    /**
     * Invoiced (facturado) per rep for the current month — reproduces NetSuite
     * report cr=270 "Sales by Sales Rep Summary" (Accounting Book = US Dollar,
     * Date = this month), Contribution Transaction Total column.
     *
     * Model (validated to the cent against cr=270 for June 2026):
     *  - Posting AR transactions: Invoices (CustInvc) minus Credit Memos (CustCred)
     *  - Revenue only: item lines posting to an Income account (taxline='F',
     *    mainline='F'); tax lines and non-sales postings such as bounced-cheque
     *    re-invoices (OthCurrAsset) or manual compensation credits (Equity) excluded
     *  - All currencies converted to USD at NetSuite's consolidated period rate
     *    (consolidatedUsdFactors) -- the same basis the report uses to show ARS in USD
     *  - Weighted by each rep's sales-team contribution (so split credit is honoured
     *    and secondary reps get only their share). Invoice item lines are stored
     *    negative and credit-memo lines positive, so the signed SUM nets correctly;
     *    we flip the sign to report sales as positive.
     *
     * Returns [canonicalRepName => amount], or null on API failure.
     */
    private function fetchInvoiced(int $year, int $month): ?array
    {
        $first   = sprintf('01/%02d/%04d', $month, $year);
        $next    = Carbon::create($year, $month, 1)->addMonth();
        $nextStr = sprintf('01/%02d/%04d', $next->month, $next->year);

        // Per-currency factors that convert a transaction's foreign amount into USD
        // using NetSuite's consolidated period rate -- the same basis the USD reports
        // (e.g. cr=270 "Sales by Sales Rep") use to present ARS invoices in USD.
        $usdFactor = $this->consolidatedUsdFactors($year, $month);

        $rows = $this->suiteqlQuery(
            "SELECT tst.employee AS employee, cur.symbol AS cur, SUM(net.amt * tst.contribution) AS tot " .
            "FROM transactionsalesteam tst " .
            "JOIN (" .
            "  SELECT tl.transaction AS tid, SUM(tl.foreignamount) AS amt " .
            "  FROM transactionline tl " .
            "  JOIN transaction t ON t.id = tl.transaction " .
            "  JOIN account a ON a.id = tl.account " .
            "  WHERE t.type IN ('CustInvc','CustCred') " .
            "  AND t.trandate >= TO_DATE('{$first}','DD/MM/YYYY') " .
            "  AND t.trandate < TO_DATE('{$nextStr}','DD/MM/YYYY') " .
            "  AND tl.taxline = 'F' AND tl.mainline = 'F' " .
            // Count only revenue lines -- the same basis as the cr=270 "Sales by
            // Sales Rep" report. Excludes non-income postings such as bounced-cheque
            // re-invoices (Cheques Rechazados / OthCurrAsset) and manual compensation
            // credits (Opening Balance / Equity), which are not real sales.
            "  AND a.accttype = 'Income' " .
            "  GROUP BY tl.transaction" .
            ") net ON net.tid = tst.transaction " .
            "JOIN transaction t2 ON t2.id = tst.transaction " .
            "JOIN currency cur ON cur.id = t2.currency " .
            "GROUP BY tst.employee, cur.symbol"
        );

        if ($rows === null) {
            return null;
        }

        $out = [];
        foreach ($rows as $row) {
            $entityId = (int) ($row['employee'] ?? 0);
            $repName  = $this->entityNames[$entityId] ?? null;
            if (!$repName) {
                continue; // employee not a tracked sales rep
            }
            $cur    = $row['cur'] ?? 'USD';
            $factor = $usdFactor[$cur] ?? null;
            if ($factor === null) {
                // No consolidated rate for this currency: keep USD as-is, but skip
                // anything else rather than report an un-converted (inflated) figure.
                if ($cur !== 'USD') {
                    Log::warning('fetchInvoiced: no USD rate for currency, skipping', ['cur' => $cur, 'period' => "{$month}/{$year}"]);
                    continue;
                }
                $factor = 1.0;
            }
            $repName = $this->normalizeName($repName);
            // Item lines are negative for invoices; flip so sales read positive.
            $out[$repName] = ($out[$repName] ?? 0) - (float) ($row['tot'] ?? 0) * $factor;
        }
        return $out;
    }

    /**
     * Per-currency multipliers that convert a transaction's foreign amount into USD
     * using NetSuite's consolidated period rate -- the same basis as the USD reports
     * (e.g. cr=270). Returns [currencySymbol => factor] with usdValue = foreignAmount
     * * factor; USD maps to 1.0.
     *
     * consolidatedexchangerate stores, per period, the average rate from each currency
     * to the base subsidiary currency (ARS here): averagerate = ARS per 1 unit of the
     * fromcurrency. So factor = (ARS per unit) / (ARS per USD). Returns [] if the
     * lookup fails, in which case fetchInvoiced falls back to USD-only.
     */
    private function consolidatedUsdFactors(int $year, int $month): array
    {
        $first   = sprintf('01/%02d/%04d', $month, $year);
        $next    = Carbon::create($year, $month, 1)->addMonth();
        $nextStr = sprintf('01/%02d/%04d', $next->month, $next->year);

        $rows = $this->suiteqlQuery(
            "SELECT cf.symbol AS sym, cer.averagerate AS rate " .
            "FROM consolidatedexchangerate cer " .
            "JOIN currency cf ON cf.id = cer.fromcurrency " .
            "WHERE cer.tocurrency = (SELECT id FROM currency WHERE symbol = 'ARS') " .
            "AND cer.accountingbook = 1 " .
            "AND cer.periodstartdate >= TO_DATE('{$first}','DD/MM/YYYY') " .
            "AND cer.periodstartdate < TO_DATE('{$nextStr}','DD/MM/YYYY')"
        );
        if (!$rows) {
            return [];
        }

        $arsPer = [];
        foreach ($rows as $r) {
            $sym = $r['sym'] ?? null;
            if ($sym !== null) {
                $arsPer[$sym] = (float) ($r['rate'] ?? 0);
            }
        }
        $arsPerUsd = $arsPer['USD'] ?? 0.0;
        if ($arsPerUsd <= 0) {
            return [];
        }

        $factors = ['USD' => 1.0];
        foreach ($arsPer as $sym => $rate) {
            if ($rate > 0) {
                $factors[$sym] = $rate / $arsPerUsd;
            }
        }
        return $factors;
    }

    /**
     * "Zona gris" — sold but not yet invoiced ("Importe pendiente USD"), per rep.
     * Reproduces Luciano's saved search 805 (Sales Orders, "Importe pendiente USD"):
     * the un-fulfilled value of open sales orders whose promised ship date
     * (custcol_3k_fecha_envio_cumplimiento / "Fecha de envío") is on or before the
     * end of the current month — i.e. From empty, To = end of month, Sales Rep = All.
     *
     * Per line, pending = (ordered qty − fulfilled qty) × unit price, where unit
     * price = foreignamount / quantity. SO lines store quantity & foreignamount
     * negative, so the ratio is a positive unit price and (|qty|−shiprecv) is the
     * remaining quantity. USD-currency orders only (the search reports in USD;
     * a few ARS orders convert to a negligible USD amount and are omitted).
     * Attributed to the order's primary sales rep. Returns [canonicalRepName => amount].
     */
    private function fetchSoldNotInvoiced(int $year, int $month, ?string $from = null): array
    {
        $eom = Carbon::create($year, $month, 1)->endOfMonth()->format('d/m/Y');
        // Optional lower bound on ship date. The current-month card passes none (it
        // includes all overdue/back-dated open orders); the next-month card passes
        // the first day of next month so it shows only orders pertaining to next
        // month, with the current month closed out.
        $fromClause = $from !== null
            ? "AND tl.custcol_3k_fecha_envio_cumplimiento >= TO_DATE('{$from}','DD/MM/YYYY') "
            : '';

        $rows = $this->suiteqlQuery(
            "SELECT tst.employee AS employee, " .
            "SUM((ABS(tl.quantity) - NVL(tl.quantityshiprecv,0)) * (tl.foreignamount / tl.quantity)) AS tot " .
            "FROM transactionline tl " .
            "JOIN transaction t ON t.id = tl.transaction " .
            "JOIN transactionsalesteam tst ON tst.transaction = t.id AND tst.isprimary = 'T' " .
            "WHERE t.type = 'SalesOrd' " .
            "AND t.currency = (SELECT id FROM currency WHERE symbol = 'USD') " .
            "AND tl.mainline = 'F' AND tl.taxline = 'F' " .
            $fromClause .
            "AND tl.custcol_3k_fecha_envio_cumplimiento <= TO_DATE('{$eom}','DD/MM/YYYY') " .
            "AND (ABS(tl.quantity) - NVL(tl.quantityshiprecv,0)) > 0 " .
            "AND t.status NOT IN ('C','G','H') " .
            "GROUP BY tst.employee"
        );

        if ($rows === null) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $entityId = (int) ($row['employee'] ?? 0);
            $repName  = $this->entityNames[$entityId] ?? null;
            if (!$repName) {
                continue; // employee not a tracked sales rep
            }
            $repName = $this->normalizeName($repName);
            $out[$repName] = ($out[$repName] ?? 0) + (float) ($row['tot'] ?? 0);
        }
        return $out;
    }

    /**
     * "Remito por facturar" — delivered (remito issued) but not yet invoiced, per rep, USD.
     * Reproduces NetSuite saved search 810 ("TEK - GOAS con Remitos por Facturar en USD"),
     * validated to the cent against the report for June 2026.
     *
     * Search criteria: Sales Orders, item lines (mainline='F', taxline='F'), status
     * Pending Billing or Pending Billing/Partially Fulfilled ('F'/'E'), quantity
     * shipped/received > 0, document number ≠ 29964 (a manual exclusion in the search).
     * Per-line value = unit price × (qty shipped − qty billed); summed per order and
     * attributed to the order's primary sales rep.
     *
     * The report values amounts via the "US Dollar Accounting" book; that book is not
     * exposed to SuiteQL (only the ARS primary book is), so we use foreignamount and
     * restrict to USD-currency orders — all current pending-billing orders are USD, so
     * the figures match exactly. This is NOT double-counted with fetchSoldNotInvoiced:
     * that counts quantity still to ship, this counts quantity already shipped not billed.
     *
     * No date filter — the full outstanding backlog regardless of order date.
     * Returns [canonicalRepName => amount].
     */
    private function fetchPendingBilling(): array
    {
        $rows = $this->suiteqlQuery(
            "SELECT tst.employee AS employee, " .
            "SUM(tl.foreignamount / NULLIF(tl.quantity,0) * (tl.quantityshiprecv - NVL(tl.quantitybilled,0))) AS tot " .
            "FROM transactionline tl " .
            "JOIN transaction t ON t.id = tl.transaction " .
            "JOIN transactionsalesteam tst ON tst.transaction = t.id AND tst.isprimary = 'T' " .
            "WHERE t.type = 'SalesOrd' " .
            "AND tl.mainline = 'F' AND tl.taxline = 'F' " .
            "AND t.status IN ('E','F') " .
            "AND t.currency = (SELECT id FROM currency WHERE symbol = 'USD') " .
            "AND tl.quantityshiprecv > 0 " .
            "AND t.tranid <> '29964' " .
            "GROUP BY tst.employee"
        );

        if ($rows === null) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $entityId = (int) ($row['employee'] ?? 0);
            $repName  = $this->entityNames[$entityId] ?? null;
            if (!$repName) {
                continue; // employee not a tracked sales rep
            }
            $repName = $this->normalizeName($repName);
            $out[$repName] = ($out[$repName] ?? 0) + (float) ($row['tot'] ?? 0);
        }
        return $out;
    }

    private function buildOauthConnection(): array
    {
        $realm      = config('integrations.netsuite.realm');
        $nonce      = bin2hex(random_bytes(11));
        $timestamp  = time();
        $consumerKey    = config('integrations.netsuite.restlet.consumer_key');
        $consumerSecret = config('integrations.netsuite.restlet.consumer_secret');
        $token      = config('integrations.netsuite.restlet.token');
        $tokenSecret    = config('integrations.netsuite.restlet.token_secret');
        $version    = config('integrations.netsuite.version', '1.0');

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
            'Prefer'         => 'transient',
            'Content-Type'   => 'application/json',
            'Content-Length' => strlen($body),
            'Host'           => "{$c['realm']}.restlets.api.netsuite.com",
            'Authorization'  => "OAuth realm=\"{$c['realm']}\","
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

        $totalWorkingDays   = 0;
        $elapsedWorkingDays = 0;
        $daysInMonth        = $now->daysInMonth;

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
        return !in_array($date->format('Y-m-d'), $holidays);
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
