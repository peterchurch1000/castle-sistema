<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Operaciones dashboard (/operaciones).
 *
 * Task 021 — splits the value of open sales orders still to deliver ("monto por
 * entregar") into what is already past its promised delivery date (vencido) vs
 * what is still within the delivery timeframe (en plazo), using the promised
 * ship date custom field custcol_3k_fecha_envio_cumplimiento ("Fecha de envío").
 *
 * Reproduces the logic of NetSuite saved search 805
 * ("TEK - GOAS POR FECHA DE ENTREGA X ENTREGAR", Importe pendiente USD) directly
 * in SuiteQL, so it needs no RESTlet/saved-search access. Pending per line =
 * (ordered qty - fulfilled qty) x unit price, USD orders only, open statuses.
 *
 * Task 022 — DIFOT (Delivery In Full On Time) cards. Reproduces the production
 * DIFOT KPI saved searches (GEP - DIFOT … - KPI) directly in SuiteQL, so no
 * saved-search/RESTlet access is needed. Each card = AVG of a per-SO-line on-time
 * percent formula for the current month. See fetchDifot().
 */
class OperacionesController extends Controller
{
    public function index()
    {
        $totals = $this->fetchDeliverySplit();
        $byRep  = $this->fetchDeliverySplitByRep();
        $difot  = $this->fetchDifot();

        return view('operaciones', [
            'totals'    => $totals,
            'byRep'     => $byRep,
            'difot'     => $difot,
            'fetchedAt' => $totals !== null ? now() : null,
        ]);
    }

    /**
     * DIFOT KPI cards for the current month, reproduced from the NetSuite
     * "GEP - DIFOT … - KPI" saved searches in SuiteQL.
     *
     *  - produccion  (search 761) — manufactured items, excl. printed-tape ("Impres") category.
     *  - cinta       (search 1504) — printed-tape ("CINTA IMPRESA") items.
     *  - operaciones (search 674)  — delivery-on-time; run via the DIFOT RESTlet (fetchDifotOperaciones()).
     *  - compras     (search 1338) — purchasing DIFOT; Item Receipt vs source PO lead time.
     *
     * Each value: ['pct' => float, 'n' => int] or null when unavailable.
     * Validated against the on-screen searches (current month exact: Producción & Cinta = 100%).
     */
    private function fetchDifot(): array
    {
        return Cache::store('file')->remember('difot_kpis_v3', 600, function () {
            // On-time percent formula, per SO line, reproduced from the saved search
            // result column "Porcentaje de cumplimiento II".
            $caseProd =
                "CASE
                  WHEN t.custbody13='T' THEN NULL
                  WHEN t.custbody18='T' THEN NULL
                  WHEN t.custbody_3k_goas_con_cuestiones='T' THEN NULL
                  WHEN tl.actualshipdate <= tl.custcol_3k_fecha_envio_cumplimiento THEN 1
                  WHEN tl.custcol_3k_fecha_envio_cumplimiento < (tl.custcol_3k_fecha_aprobado_operaciones + i.custitem_3k_plazo_produccion)
                    THEN CASE WHEN NVL(wo.actualproductionenddate, CURRENT_DATE) - tl.custcol_3k_fecha_aprobado_operaciones <= i.custitem_3k_plazo_produccion THEN 1 ELSE 0 END
                  ELSE CASE WHEN tl.custcol_3k_fecha_envio_cumplimiento >= wo.actualproductionenddate THEN 1 ELSE 0 END
                END";
            // Cinta Impresa search filters custbody13/18 as hard criteria, so its
            // formula has no NULL guards.
            $caseCinta =
                "CASE
                  WHEN tl.actualshipdate <= tl.custcol_3k_fecha_envio_cumplimiento THEN 1
                  WHEN tl.custcol_3k_fecha_envio_cumplimiento < (tl.custcol_3k_fecha_aprobado_operaciones + i.custitem_3k_plazo_produccion)
                    THEN CASE WHEN NVL(wo.actualproductionenddate, CURRENT_DATE) - tl.custcol_3k_fecha_aprobado_operaciones <= i.custitem_3k_plazo_produccion THEN 1 ELSE 0 END
                  ELSE CASE WHEN tl.custcol_3k_fecha_envio_cumplimiento >= wo.actualproductionenddate THEN 1 ELSE 0 END
                END";

            // Shared base: SO line -> applying Work Order (linktype SpecOrd), WO Built (status G),
            // approval date set, grouped to the WO's actual-production-end month = current month.
            $base =
                "FROM transaction t
                  JOIN transactionLine tl ON tl.transaction = t.id
                  JOIN item i ON i.id = tl.item
                  JOIN nexttransactionlink ntl ON ntl.previousdoc = t.id AND ntl.linktype = 'SpecOrd'
                  JOIN transaction wo ON wo.id = ntl.nextdoc AND wo.type = 'WorkOrd' AND wo.status = 'G'
                  WHERE t.type = 'SalesOrd' AND tl.mainline = 'F' AND tl.taxline = 'F'
                  AND tl.custcol_3k_fecha_aprobado_operaciones IS NOT NULL
                  AND NVL(t.custbody17,'F') = 'F' AND NVL(wo.custbody17,'F') = 'F'
                  AND t.tranid <> '4070'
                  AND TO_CHAR(wo.actualproductionenddate,'YYYY-MM') = TO_CHAR(CURRENT_DATE,'YYYY-MM')";

            $prod = $this->suiteqlQuery(
                "SELECT ROUND(AVG($caseProd) * 100, 1) pct, COUNT(*) n $base"
                . " AND i.itemid NOT LIKE 'CAS-%'"
                . " AND (i.custitemcatplazo IS NULL OR i.custitemcatplazo NOT LIKE 'Impres%')"
            );

            $cinta = $this->suiteqlQuery(
                "SELECT ROUND(AVG($caseCinta) * 100, 1) pct, COUNT(*) n $base"
                . " AND NVL(t.custbody13,'F') = 'F' AND NVL(t.custbody18,'F') = 'F'"
                . " AND UPPER(i.description) LIKE '%IMPRESA%'"
                . " AND UPPER(i.description) NOT LIKE '%- ARNEG%'"
                . " AND UPPER(i.description) NOT LIKE '%AISLAMIENTO PIR FM APPROVALS%'"
                . " AND wo.actualproductionenddate > TO_DATE('2024-01-01','YYYY-MM-DD')"
            );

            // Compras (search 1338 "GEP - DIFOT COMPRAS - KPI"). Per Item Receipt,
            // on-time = reception date within the agreed lead time of its source
            // Purchase Order (created from). The PO is reached via the transaction
            // link table (createdfrom is not a SuiteQL column); customform 139 =
            // "CASTLE - Pedido estándar ARG". On-time if (recepcion - fecha envío OC)
            // <= max(PO duedate - fecha envío OC, vendor lead-time custentity6).
            // Excluded (NULL) when receipt custbody51='T' or PO custbody46='T'.
            // Validated exact against the live search across all months.
            $diff  = 'TO_NUMBER(t.trandate - po.custbody41)';
            $plazo = 'TO_NUMBER(po.duedate - po.custbody41)';
            $lead  = "TO_NUMBER(NVL(v.custentity6,'0'))";
            $caseCompras =
                "CASE
                  WHEN t.trandate < TO_DATE('2023-10-01','YYYY-MM-DD') THEN 0.5
                  WHEN t.trandate > TO_DATE('2023-10-01','YYYY-MM-DD') THEN
                    CASE WHEN NVL(t.custbody51,'F') = 'T' THEN NULL
                    WHEN NVL(po.custbody46,'F') = 'T' THEN NULL
                    ELSE CASE WHEN $diff <= (CASE WHEN $plazo > $lead THEN $plazo ELSE $lead END) THEN 1 ELSE 0 END
                    END
                  ELSE NULL END";
            $compras = $this->suiteqlQuery(
                "SELECT ROUND(AVG($caseCompras) * 100, 1) pct, COUNT(*) n"
                . " FROM transaction t"
                . " JOIN nexttransactionlink ntl ON ntl.nextdoc = t.id"
                . " JOIN transaction po ON po.id = ntl.previousdoc AND po.type = 'PurchOrd'"
                . " LEFT JOIN vendor v ON v.id = t.entity"
                . " WHERE t.type = 'ItemRcpt' AND po.customform = 139"
                . " AND po.custbody41 IS NOT NULL"
                . " AND po.trandate >= TO_DATE('2023-01-01','YYYY-MM-DD')"
                . " AND TO_CHAR(t.trandate,'YYYY-MM') = TO_CHAR(CURRENT_DATE,'YYYY-MM')"
            );

            $pick = function (?array $rows): ?array {
                if ($rows === null || !isset($rows[0])) {
                    return null;
                }
                if (($rows[0]['n'] ?? 0) == 0) {
                    return null;
                }
                return ['pct' => (float) ($rows[0]['pct'] ?? 0), 'n' => (int) ($rows[0]['n'] ?? 0)];
            };

            return [
                'produccion'  => $pick($prod),
                'cinta'       => $pick($cinta),
                'operaciones' => $this->fetchDifotOperaciones(),
                'compras'     => $pick($compras),
                'entrega'     => $this->fetchDifotEntrega(),
            ];
        });
    }

    /**
     * DIFOT de entrega — on-time delivery rate for the last complete calendar month.
     * Scope: Sales Orders with an Actual Ship Date (actualshipdate) in that month.
     * On time = real delivery date (custbody_tek_fecha_entrega) on or before the
     * estimated delivery date (custbody_gep_fecha_entrega_estimado). Only orders with
     * BOTH dates are evaluable; rate = a_tiempo / evaluables. Delivery dates lag, so
     * this deliberately reports the last complete month, not the current one.
     */
    private function fetchDifotEntrega(): ?array
    {
        $now   = Carbon::now('America/Argentina/Buenos_Aires');
        $som   = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $first = $som->format('d/m/Y');
        $next  = $som->copy()->addMonthNoOverflow()->format('d/m/Y');
        $meses = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
            'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

        $rows = $this->suiteqlQuery(
            "SELECT COUNT(*) AS total_shipped, " .
            "COUNT(CASE WHEN custbody_tek_fecha_entrega IS NOT NULL THEN 1 END) AS con_real, " .
            "COUNT(CASE WHEN custbody_tek_fecha_entrega IS NOT NULL AND custbody_gep_fecha_entrega_estimado IS NOT NULL THEN 1 END) AS evaluables, " .
            "COUNT(CASE WHEN custbody_tek_fecha_entrega IS NOT NULL AND custbody_gep_fecha_entrega_estimado IS NOT NULL AND custbody_tek_fecha_entrega <= custbody_gep_fecha_entrega_estimado THEN 1 END) AS a_tiempo " .
            "FROM transaction " .
            "WHERE type = 'SalesOrd' " .
            "AND actualshipdate >= TO_DATE('{$first}','DD/MM/YYYY') " .
            "AND actualshipdate < TO_DATE('{$next}','DD/MM/YYYY')"
        );
        if ($rows === null || !isset($rows[0])) {
            return null;
        }
        $r      = $rows[0];
        $eval   = (int) ($r['evaluables'] ?? 0);
        $ontime = (int) ($r['a_tiempo'] ?? 0);

        return [
            'pct'           => $eval > 0 ? round($ontime / $eval * 100, 1) : null,
            'n'             => $eval,
            'a_tiempo'      => $ontime,
            'evaluables'    => $eval,
            'con_real'      => (int) ($r['con_real'] ?? 0),
            'total_shipped' => (int) ($r['total_shipped'] ?? 0),
            'periodo'       => $meses[$som->month],
        ];
    }

    /**
     * Operaciones DIFOT card — the current-month "cumplimiento" of NetSuite saved
     * search 674 ("GEP - CUMPLIMIENTO OPERACIONES - AL DIA"), pace-adjusted.
     *
     * Search 674 cannot be reproduced in SuiteQL (its on-time metric depends on the
     * applyingtransaction delivery join at a row grain the link tables don't expose),
     * and it throws SSS_INVALID_SRCH_FILTER when run headless because of 3K-SuiteApp
     * checkbox filters. So we run it through our "Castle DIFOT Saved Search API"
     * RESTlet, which loads 674 and re-runs it via search.create with those checkbox
     * filters translated to equivalent formula filters. Output matches the live
     * search exactly. Returns ['pct' => float, 'n' => int] or null.
     */
    private function fetchDifotOperaciones(): ?array
    {
        $rows = $this->difotSearchRestlet(config('integrations.netsuite.difot.search_id'));
        if ($rows === null) {
            return null;
        }

        $month = now()->format('Y-m');
        foreach ($rows as $row) {
            // The month group is returned under the "Fecha de Entrega" label.
            if (($row['Fecha de Entrega'] ?? null) !== $month) {
                continue;
            }
            $pct = (float) str_replace(['%', ','], ['', ''], (string) ($row['CUMPLIMIENTO REAL'] ?? '0'));
            $n   = (int) ($row['__cnt'] ?? 0);
            if ($n === 0) {
                return null;
            }
            return ['pct' => round($pct, 1), 'n' => $n];
        }
        return null;
    }

    /**
     * Invoke the "Castle DIFOT Saved Search API" RESTlet (OAuth 1.0 / HMAC-SHA256,
     * restlet credentials) for a saved search ID, returning its result rows.
     */
    private function difotSearchRestlet(string $searchId): ?array
    {
        $realm          = config('integrations.netsuite.realm');
        $consumerKey    = config('integrations.netsuite.restlet.consumer_key');
        $consumerSecret = config('integrations.netsuite.restlet.consumer_secret');
        $token          = config('integrations.netsuite.restlet.token');
        $tokenSecret    = config('integrations.netsuite.restlet.token_secret');
        $script         = config('integrations.netsuite.difot.restlet_script');
        $deploy         = config('integrations.netsuite.difot.restlet_deploy');

        $baseUrl = "https://{$realm}.restlets.api.netsuite.com/app/site/hosting/restlet.nl";
        $nonce   = bin2hex(random_bytes(11));
        $ts      = time();
        $key     = rawurlencode($consumerSecret) . '&' . rawurlencode($tokenSecret);

        // The script/deploy query params must be part of the OAuth signature base.
        $allParams = [
            'deploy'                 => $deploy,
            'script'                 => $script,
            'oauth_consumer_key'     => $consumerKey,
            'oauth_nonce'            => $nonce,
            'oauth_signature_method' => 'HMAC-SHA256',
            'oauth_timestamp'        => $ts,
            'oauth_token'            => $token,
            'oauth_version'          => '1.0',
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
            $response = Http::timeout(45)
                ->withHeaders(['Authorization' => $auth, 'Content-Type' => 'application/json'])
                ->post($baseUrl . '?script=' . rawurlencode($script) . '&deploy=' . rawurlencode($deploy), [
                    'searchID' => $searchId,
                ]);

            if (!$response->successful()) {
                Log::error('DIFOT RESTlet HTTP error', ['status' => $response->status(), 'body' => substr($response->body(), 0, 300)]);
                return null;
            }
            $json = $response->json();
            if (!isset($json['results'])) {
                Log::error('DIFOT RESTlet returned no results', ['body' => substr($response->body(), 0, 300)]);
                return null;
            }
            return $json['results'];
        } catch (\Exception $e) {
            Log::error('DIFOT RESTlet exception', ['msg' => $e->getMessage()]);
            return null;
        }
    }

    /** Bucketed totals (overdue vs within-timeframe) for all open SO lines still to deliver. */
    private function fetchDeliverySplit(): ?array
    {
        $eom = Carbon::now()->endOfMonth()->format('d/m/Y');
        $rows = $this->suiteqlQuery(
            "SELECT " .
            "CASE WHEN tl.custcol_3k_fecha_envio_cumplimiento < TRUNC(SYSDATE) THEN 'overdue' ELSE 'ontime' END AS bucket, " .
            "COUNT(DISTINCT t.id) AS orders, " .
            "ROUND(SUM((ABS(tl.quantity) - NVL(tl.quantityshiprecv,0)) * (tl.foreignamount / tl.quantity)), 2) AS pending_usd " .
            "FROM transactionline tl JOIN transaction t ON t.id = tl.transaction " .
            "WHERE t.type = 'SalesOrd' " .
            "AND t.currency = (SELECT id FROM currency WHERE symbol = 'USD') " .
            "AND tl.mainline = 'F' AND tl.taxline = 'F' " .
            "AND (ABS(tl.quantity) - NVL(tl.quantityshiprecv,0)) > 0 " .
            "AND t.status NOT IN ('C','G','H') " .
            "AND tl.custcol_3k_fecha_envio_cumplimiento IS NOT NULL " .
            "AND tl.custcol_3k_fecha_envio_cumplimiento <= TO_DATE('{$eom}','DD/MM/YYYY') " .
            "GROUP BY CASE WHEN tl.custcol_3k_fecha_envio_cumplimiento < TRUNC(SYSDATE) THEN 'overdue' ELSE 'ontime' END"
        );

        if ($rows === null) {
            return null;
        }

        $out = [
            'overdue' => ['amount' => 0.0, 'orders' => 0],
            'ontime'  => ['amount' => 0.0, 'orders' => 0],
        ];
        foreach ($rows as $row) {
            $b = $row['bucket'] ?? '';
            if (!isset($out[$b])) {
                continue;
            }
            $out[$b] = [
                'amount' => (float) ($row['pending_usd'] ?? 0),
                'orders' => (int) ($row['orders'] ?? 0),
            ];
        }
        return $out;
    }

    /** Same split broken down by primary sales rep, for the detail table. */
    private function fetchDeliverySplitByRep(): array
    {
        $eom = Carbon::now()->endOfMonth()->format('d/m/Y');
        $rows = $this->suiteqlQuery(
            "SELECT BUILTIN.DF(tst.employee) AS rep, " .
            "ROUND(SUM(CASE WHEN tl.custcol_3k_fecha_envio_cumplimiento < TRUNC(SYSDATE) " .
            "  THEN (ABS(tl.quantity) - NVL(tl.quantityshiprecv,0)) * (tl.foreignamount / tl.quantity) ELSE 0 END), 2) AS overdue_usd, " .
            "ROUND(SUM(CASE WHEN tl.custcol_3k_fecha_envio_cumplimiento >= TRUNC(SYSDATE) " .
            "  THEN (ABS(tl.quantity) - NVL(tl.quantityshiprecv,0)) * (tl.foreignamount / tl.quantity) ELSE 0 END), 2) AS ontime_usd " .
            "FROM transactionline tl " .
            "JOIN transaction t ON t.id = tl.transaction " .
            "JOIN transactionsalesteam tst ON tst.transaction = t.id AND tst.isprimary = 'T' " .
            "WHERE t.type = 'SalesOrd' " .
            "AND t.currency = (SELECT id FROM currency WHERE symbol = 'USD') " .
            "AND tl.mainline = 'F' AND tl.taxline = 'F' " .
            "AND (ABS(tl.quantity) - NVL(tl.quantityshiprecv,0)) > 0 " .
            "AND t.status NOT IN ('C','G','H') " .
            "AND tl.custcol_3k_fecha_envio_cumplimiento IS NOT NULL " .
            "AND tl.custcol_3k_fecha_envio_cumplimiento <= TO_DATE('{$eom}','DD/MM/YYYY') " .
            "GROUP BY BUILTIN.DF(tst.employee)"
        );

        if ($rows === null) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $overdue = (float) ($row['overdue_usd'] ?? 0);
            $ontime  = (float) ($row['ontime_usd'] ?? 0);
            if (round($overdue + $ontime, 2) == 0.0) {
                continue;
            }
            $out[] = [
                'rep'     => $row['rep'] ?? '-',
                'overdue' => $overdue,
                'ontime'  => $ontime,
            ];
        }
        usort($out, fn ($a, $b) => $b['overdue'] <=> $a['overdue']);
        return $out;
    }

    /**
     * SuiteQL query via the REST query API (OAuth 1.0 / HMAC-SHA256).
     * Mirrors DashboardController::suiteqlQuery (same integration credentials).
     */
    private function suiteqlQuery(string $sql): ?array
    {
        $realm          = config('integrations.netsuite.realm');
        $consumerKey    = config('integrations.netsuite.suiteql.consumer_key');
        $consumerSecret = config('integrations.netsuite.suiteql.consumer_secret');
        $token          = config('integrations.netsuite.suiteql.token');
        $tokenSecret    = config('integrations.netsuite.suiteql.token_secret');

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
                Log::error('Operaciones SuiteQL error', ['sql' => substr($sql, 0, 120), 'body' => $response->body()]);
                return null;
            }
            return $response->json('items') ?? [];
        } catch (\Exception $e) {
            Log::error('Operaciones SuiteQL exception', ['msg' => $e->getMessage()]);
            return null;
        }
    }
}
