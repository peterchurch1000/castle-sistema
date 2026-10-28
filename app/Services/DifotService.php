<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * DIFOT KPI service (task 363).
 *
 * Owns the DIFOT calculation + caching for the /operaciones cards, shared by the
 * OperacionesController (read path) and the castle:refresh command (forced-refresh
 * path). Each of the five components is cached independently in the default
 * (database/MariaDB) store — never the file store — so ownership/permissions can
 * never make the cache unwritable, and a transient upstream failure on one
 * component can never poison the others.
 *
 * Failure handling is explicit: NetSuite governance / transport failures
 * (HTTP 429 CONCURRENCY_LIMIT_EXCEEDED, SSS_REQUEST_LIMIT_EXCEEDED) are treated as
 * "failed" and are NEVER cached — the previous good value is left untouched. A
 * legitimate zero-row result is "ok" and IS cached (renders as "sin datos").
 *
 * Components:
 *  - produccion  — manufactured items (excl. printed-tape), SuiteQL (search 761).
 *  - cinta       — printed-tape ("CINTA IMPRESA") items, SuiteQL (search 1504).
 *  - operaciones — delivery-on-time via the "Castle DIFOT Saved Search API" RESTlet
 *                  (script 1503 / deploy 1 / search 674).
 *  - compras     — purchasing DIFOT, SuiteQL (search 1338).
 *  - entrega     — DIFOT de entrega, on-time delivery for the last complete month, SuiteQL.
 */
class DifotService
{
    /** Cache TTL — must exceed the scheduled warm interval (15 min) with margin. */
    private const TTL_MINUTES = 45;

    private const COMPONENTS = ['produccion', 'cinta', 'operaciones', 'compras', 'entrega'];

    /**
     * READ path (page loads). Return each component from cache; compute only on a
     * miss (which stores the result on success). Shape matches the old
     * fetchDifot(): ['produccion'=>?array, 'cinta'=>?array, ...].
     */
    public function cards(): array
    {
        $out = [];
        foreach (self::COMPONENTS as $component) {
            [$hit, $value] = $this->readCache($this->key($component));
            if ($hit) {
                $out[$component] = $value;

                continue;
            }
            [, $value] = $this->refreshComponent($component);
            $out[$component] = $value;
        }

        return $out;
    }

    /**
     * Read a component's cache entry. Values are stored wrapped in an envelope
     * (['v' => $value]) so a legitimate null value is distinguishable from a cache
     * miss — Laravel's Cache::has()/get() treat a bare cached null as absent, which
     * would make an empty-but-valid component recompute (and hit NetSuite) every
     * page load.
     *
     * @return array{0:bool, 1:mixed} [hit, value]
     */
    private function readCache(string $key): array
    {
        $hit = Cache::get($key);
        if (is_array($hit) && array_key_exists('v', $hit)) {
            return [true, $hit['v']];
        }

        return [false, null];
    }

    /**
     * FORCED-refresh path (castle:refresh --only=difot). Recompute every component
     * from NetSuite regardless of cache state, replacing the cached entry only on
     * success. Returns a report for the command's exit status.
     */
    public function refreshAll(): array
    {
        $failed = [];
        foreach (self::COMPONENTS as $component) {
            [$status] = $this->refreshComponent($component, true);
            if ($status === 'failed') {
                $failed[] = $component;
            }
        }

        return [
            'success' => count($failed) === 0,
            'refreshed' => count(self::COMPONENTS) - count($failed),
            'failed' => $failed,
        ];
    }

    // ── Delivery-split cache (/operaciones "Monto por entregar") ──────────────
    //
    // Totals + by-rep split were previously fetched live on every page load, so a
    // single NetSuite hiccup blanked the whole section. They are now cached with
    // the same stale-fallback contract as the DIFOT components (DB cache, never the
    // file store; governance/429/timeout = failed + never cached; zero rows = ok +
    // cached), but kept separate from COMPONENTS (different value shape, own warm
    // cadence) and carry a last-good timestamp.

    private const DELIVERY_COMPONENTS = ['totals', 'byrep'];

    /**
     * READ path (/operaciones page load). Cached totals + by-rep split, computed on
     * a miss. 'fetchedAt' is the OLDER of the two components' last-good timestamps,
     * and only when BOTH are present — so a never-succeeded component can never let
     * the header imply the section is current.
     *
     * @return array{totals:?array, byRep:array, fetchedAt:?string}
     */
    public function deliverySplit(): array
    {
        [$hitT, $totals, $tsT] = $this->readDeliveryCache($this->deliveryKey('totals'));
        if (! $hitT) {
            [, $totals, $tsT] = $this->refreshDeliveryComponent('totals');
        }
        [$hitR, $byRep, $tsR] = $this->readDeliveryCache($this->deliveryKey('byrep'));
        if (! $hitR) {
            [, $byRep, $tsR] = $this->refreshDeliveryComponent('byrep');
        }

        $fetchedAt = ($tsT !== null && $tsR !== null)
            ? (Carbon::parse($tsT)->lte(Carbon::parse($tsR)) ? $tsT : $tsR)
            : null;

        return [
            'totals' => $totals,
            'byRep' => $byRep ?? [],
            'fetchedAt' => $fetchedAt,
        ];
    }

    /**
     * FORCED warm (castle:refresh --only=operaciones). Recompute both components,
     * replacing the cached entry only on success. Mirrors refreshAll().
     *
     * @return array{success:bool, refreshed:int, failed:array}
     */
    public function refreshDeliverySplit(): array
    {
        $failed = [];
        foreach (self::DELIVERY_COMPONENTS as $component) {
            [$status] = $this->refreshDeliveryComponent($component, true);
            if ($status === 'failed') {
                $failed[] = $component;
            }
        }

        return [
            'success' => count($failed) === 0,
            'refreshed' => count(self::DELIVERY_COMPONENTS) - count($failed),
            'failed' => $failed,
        ];
    }

    private function deliveryKey(string $component): string
    {
        return "opsplit_v1_{$component}";
    }

    /**
     * Read a delivery-split cache entry. Envelope is ['v' => value, 't' => isoTs].
     *
     * @return array{0:bool, 1:mixed, 2:?string} [hit, value, timestamp]
     */
    private function readDeliveryCache(string $key): array
    {
        $hit = Cache::get($key);
        if (is_array($hit) && array_key_exists('v', $hit)) {
            return [true, $hit['v'], $hit['t'] ?? null];
        }

        return [false, null, null];
    }

    /**
     * Compute one delivery-split component under a per-component lock (mirrors
     * refreshComponent). On success writes BOTH a fresh copy (TTL, drives
     * recompute-on-read) and a non-expiring last-good copy; on failure it never
     * overwrites and falls back to the last-good copy so the section persists stale
     * rather than blanking — even for an outage longer than the TTL.
     *
     * @return array{0:'ok'|'failed', 1:mixed, 2:?string} [status, value, timestamp]
     */
    private function refreshDeliveryComponent(string $component, bool $force = false): array
    {
        $key = $this->deliveryKey($component);
        $lastGoodKey = $key.':lastgood';
        $lock = Cache::lock($key.':lock', 60);
        try {
            $lock->block(10);

            if (! $force) {
                [$hit, $value, $ts] = $this->readDeliveryCache($key);
                if ($hit) {
                    return ['ok', $value, $ts];
                }
            }

            [$status, $value] = $this->computeDelivery($component);
            if ($status === 'ok') {
                $ts = now()->toIso8601String();
                $envelope = ['v' => $value, 't' => $ts];
                // Fresh copy expires so a healthy page load recomputes after the TTL
                // (self-heal if the warm stops); last-good never expires so an outage
                // outliving the TTL still serves stale instead of blanking.
                Cache::put($key, $envelope, now()->addMinutes(self::TTL_MINUTES));
                Cache::forever($lastGoodKey, $envelope);

                return ['ok', $value, $ts];
            }

            // Failed upstream — never overwrite; fall back to the non-expiring
            // last-good value so the KPIs persist stale rather than blanking.
            [, $existing, $existingTs] = $this->readDeliveryCache($lastGoodKey);

            return ['failed', $existing, $existingTs];
        } catch (LockTimeoutException $e) {
            [$hit, $value, $ts] = $this->readDeliveryCache($key);
            if ($hit) {
                // A forced warm that never ran must report failure; a page read may
                // still serve the fresh value it found.
                return [$force ? 'failed' : 'ok', $value, $ts];
            }
            [, $lgValue, $lgTs] = $this->readDeliveryCache($lastGoodKey);

            return ['failed', $lgValue, $lgTs];
        } finally {
            optional($lock)->release();
        }
    }

    /** @return array{0:'ok'|'failed', 1:mixed} */
    private function computeDelivery(string $component): array
    {
        return match ($component) {
            'totals' => $this->computeDeliveryTotals(),
            'byrep' => $this->computeDeliveryByRep(),
        };
    }

    /** Bucketed totals (overdue vs within-timeframe) for all open SO lines still to deliver. */
    private function computeDeliveryTotals(): array
    {
        $som = Carbon::now()->startOfMonth()->format('d/m/Y');
        $eom = Carbon::now()->endOfMonth()->format('d/m/Y');
        $rows = $this->suiteql(
            'SELECT '.
            "CASE WHEN tl.custcol_3k_fecha_envio_cumplimiento < TRUNC(SYSDATE) THEN 'overdue' ELSE 'ontime' END AS bucket, ".
            'COUNT(DISTINCT t.id) AS orders, '.
            'ROUND(SUM((ABS(tl.quantity) - NVL(tl.quantityshiprecv,0)) * (tl.foreignamount / tl.quantity)), 2) AS pending_usd '.
            'FROM transactionline tl JOIN transaction t ON t.id = tl.transaction '.
            "WHERE t.type = 'SalesOrd' ".
            "AND t.currency = (SELECT id FROM currency WHERE symbol = 'USD') ".
            "AND tl.mainline = 'F' AND tl.taxline = 'F' ".
            'AND (ABS(tl.quantity) - NVL(tl.quantityshiprecv,0)) > 0 '.
            "AND t.status NOT IN ('C','G','H') ".
            'AND tl.custcol_3k_fecha_envio_cumplimiento IS NOT NULL '.
            "AND tl.custcol_3k_fecha_envio_cumplimiento >= TO_DATE('{$som}','DD/MM/YYYY') ".
            "AND tl.custcol_3k_fecha_envio_cumplimiento <= TO_DATE('{$eom}','DD/MM/YYYY') ".
            "GROUP BY CASE WHEN tl.custcol_3k_fecha_envio_cumplimiento < TRUNC(SYSDATE) THEN 'overdue' ELSE 'ontime' END"
        );

        if ($rows === null) {
            return ['failed', null];
        }

        $out = [
            'overdue' => ['amount' => 0.0, 'orders' => 0],
            'ontime' => ['amount' => 0.0, 'orders' => 0],
        ];
        foreach ($rows as $row) {
            $b = $row['bucket'] ?? '';
            if (! isset($out[$b])) {
                continue;
            }
            $out[$b] = [
                'amount' => (float) ($row['pending_usd'] ?? 0),
                'orders' => (int) ($row['orders'] ?? 0),
            ];
        }

        return ['ok', $out];
    }

    /** Same split broken down by primary sales rep, for the detail table. */
    private function computeDeliveryByRep(): array
    {
        $som = Carbon::now()->startOfMonth()->format('d/m/Y');
        $eom = Carbon::now()->endOfMonth()->format('d/m/Y');
        $rows = $this->suiteql(
            'SELECT BUILTIN.DF(tst.employee) AS rep, '.
            'ROUND(SUM(CASE WHEN tl.custcol_3k_fecha_envio_cumplimiento < TRUNC(SYSDATE) '.
            '  THEN (ABS(tl.quantity) - NVL(tl.quantityshiprecv,0)) * (tl.foreignamount / tl.quantity) ELSE 0 END), 2) AS overdue_usd, '.
            'ROUND(SUM(CASE WHEN tl.custcol_3k_fecha_envio_cumplimiento >= TRUNC(SYSDATE) '.
            '  THEN (ABS(tl.quantity) - NVL(tl.quantityshiprecv,0)) * (tl.foreignamount / tl.quantity) ELSE 0 END), 2) AS ontime_usd '.
            'FROM transactionline tl '.
            'JOIN transaction t ON t.id = tl.transaction '.
            "JOIN transactionsalesteam tst ON tst.transaction = t.id AND tst.isprimary = 'T' ".
            "WHERE t.type = 'SalesOrd' ".
            "AND t.currency = (SELECT id FROM currency WHERE symbol = 'USD') ".
            "AND tl.mainline = 'F' AND tl.taxline = 'F' ".
            'AND (ABS(tl.quantity) - NVL(tl.quantityshiprecv,0)) > 0 '.
            "AND t.status NOT IN ('C','G','H') ".
            'AND tl.custcol_3k_fecha_envio_cumplimiento IS NOT NULL '.
            "AND tl.custcol_3k_fecha_envio_cumplimiento >= TO_DATE('{$som}','DD/MM/YYYY') ".
            "AND tl.custcol_3k_fecha_envio_cumplimiento <= TO_DATE('{$eom}','DD/MM/YYYY') ".
            'GROUP BY BUILTIN.DF(tst.employee)'
        );

        if ($rows === null) {
            return ['failed', null];
        }

        $out = [];
        foreach ($rows as $row) {
            $overdue = (float) ($row['overdue_usd'] ?? 0);
            $ontime = (float) ($row['ontime_usd'] ?? 0);
            if (round($overdue + $ontime, 2) == 0.0) {
                continue;
            }
            $out[] = [
                'rep' => $row['rep'] ?? '-',
                'overdue' => $overdue,
                'ontime' => $ontime,
            ];
        }
        usort($out, fn ($a, $b) => $b['overdue'] <=> $a['overdue']);

        return ['ok', $out];
    }

    /**
     * Compute one component under a per-component lock, so a page miss and a
     * scheduler warm cannot duplicate the upstream call. On success it writes BOTH a
     * fresh copy (TTL, drives recompute-on-read) and a non-expiring last-good copy;
     * on failure it never overwrites and falls back to the last-good copy, so a card
     * persists stale rather than blanking — even for an outage longer than the TTL.
     *
     * @return array{0:'ok'|'failed', 1:mixed} [status, value]
     */
    private function refreshComponent(string $component, bool $force = false): array
    {
        $key = $this->key($component);
        $lastGoodKey = $key.':lastgood';
        $lock = Cache::lock($key.':lock', 60);
        try {
            $lock->block(10);

            // Another worker may have filled it while we waited for the lock.
            if (! $force) {
                [$hit, $value] = $this->readCache($key);
                if ($hit) {
                    return ['ok', $value];
                }
            }

            [$status, $value] = $this->compute($component);
            if ($status === 'ok') {
                $envelope = ['v' => $value];
                // Fresh copy expires so a healthy page load recomputes after the TTL
                // (self-heal if the warm stops); last-good never expires so an outage
                // outliving the TTL still serves stale instead of blanking.
                Cache::put($key, $envelope, now()->addMinutes(self::TTL_MINUTES));
                Cache::forever($lastGoodKey, $envelope);

                return ['ok', $value];
            }

            // Failed upstream — never overwrite; fall back to the non-expiring
            // last-good value so the card persists stale rather than blanking.
            [, $existing] = $this->readCache($lastGoodKey);

            return ['failed', $existing];
        } catch (LockTimeoutException $e) {
            [$hit, $value] = $this->readCache($key);
            // A FORCED refresh that could not acquire the lock did NOT refresh, so
            // report failure — castle:refresh's exit status must not claim success
            // for a component it never recomputed. A page read (non-forced) may
            // still serve the fresh cached value as success; otherwise fall back to
            // the non-expiring last-good value.
            if ($hit) {
                return [$force ? 'failed' : 'ok', $value];
            }
            [, $lgValue] = $this->readCache($lastGoodKey);

            return ['failed', $lgValue];
        } finally {
            optional($lock)->release();
        }
    }

    private function key(string $component): string
    {
        return "difot_v5_{$component}";
    }

    /** @return array{0:'ok'|'failed', 1:mixed} */
    private function compute(string $component): array
    {
        return match ($component) {
            'produccion' => $this->computeProduccion(),
            'cinta' => $this->computeCinta(),
            'compras' => $this->computeCompras(),
            'operaciones' => $this->computeOperaciones(),
            'entrega' => $this->computeEntrega(),
        };
    }

    // ── Component computers ──────────────────────────────────────────────────

    /** Shared base for the produccion/cinta on-time formulas (WO-built, current month). */
    private function base(): string
    {
        return "FROM transaction t
              JOIN transactionLine tl ON tl.transaction = t.id
              JOIN item i ON i.id = tl.item
              JOIN nexttransactionlink ntl ON ntl.previousdoc = t.id AND ntl.linktype = 'SpecOrd'
              JOIN transaction wo ON wo.id = ntl.nextdoc AND wo.type = 'WorkOrd' AND wo.status = 'G'
              WHERE t.type = 'SalesOrd' AND tl.mainline = 'F' AND tl.taxline = 'F'
              AND tl.custcol_3k_fecha_aprobado_operaciones IS NOT NULL
              AND NVL(t.custbody17,'F') = 'F' AND NVL(wo.custbody17,'F') = 'F'
              AND t.tranid <> '4070'
              AND TO_CHAR(wo.actualproductionenddate,'YYYY-MM') = TO_CHAR(CURRENT_DATE,'YYYY-MM')";
    }

    private function caseProd(): string
    {
        return "CASE
                  WHEN t.custbody13='T' THEN NULL
                  WHEN t.custbody18='T' THEN NULL
                  WHEN t.custbody_3k_goas_con_cuestiones='T' THEN NULL
                  WHEN tl.actualshipdate <= tl.custcol_3k_fecha_envio_cumplimiento THEN 1
                  WHEN tl.custcol_3k_fecha_envio_cumplimiento < (tl.custcol_3k_fecha_aprobado_operaciones + i.custitem_3k_plazo_produccion)
                    THEN CASE WHEN NVL(wo.actualproductionenddate, CURRENT_DATE) - tl.custcol_3k_fecha_aprobado_operaciones <= i.custitem_3k_plazo_produccion THEN 1 ELSE 0 END
                  ELSE CASE WHEN tl.custcol_3k_fecha_envio_cumplimiento >= wo.actualproductionenddate THEN 1 ELSE 0 END
                END";
    }

    private function caseCinta(): string
    {
        return 'CASE
                  WHEN tl.actualshipdate <= tl.custcol_3k_fecha_envio_cumplimiento THEN 1
                  WHEN tl.custcol_3k_fecha_envio_cumplimiento < (tl.custcol_3k_fecha_aprobado_operaciones + i.custitem_3k_plazo_produccion)
                    THEN CASE WHEN NVL(wo.actualproductionenddate, CURRENT_DATE) - tl.custcol_3k_fecha_aprobado_operaciones <= i.custitem_3k_plazo_produccion THEN 1 ELSE 0 END
                  ELSE CASE WHEN tl.custcol_3k_fecha_envio_cumplimiento >= wo.actualproductionenddate THEN 1 ELSE 0 END
                END';
    }

    private function computeProduccion(): array
    {
        $rows = $this->suiteql(
            "SELECT ROUND(AVG({$this->caseProd()}) * 100, 1) pct, COUNT(*) n {$this->base()}"
            ." AND i.itemid NOT LIKE 'CAS-%'"
            ." AND (i.custitemcatplazo IS NULL OR i.custitemcatplazo NOT LIKE 'Impres%')"
        );

        return $this->pickComponent($rows);
    }

    private function computeCinta(): array
    {
        $rows = $this->suiteql(
            "SELECT ROUND(AVG({$this->caseCinta()}) * 100, 1) pct, COUNT(*) n {$this->base()}"
            ." AND NVL(t.custbody13,'F') = 'F' AND NVL(t.custbody18,'F') = 'F'"
            ." AND UPPER(i.description) LIKE '%IMPRESA%'"
            ." AND UPPER(i.description) NOT LIKE '%- ARNEG%'"
            ." AND UPPER(i.description) NOT LIKE '%AISLAMIENTO PIR FM APPROVALS%'"
            ." AND wo.actualproductionenddate > TO_DATE('2024-01-01','YYYY-MM-DD')"
        );

        return $this->pickComponent($rows);
    }

    private function computeCompras(): array
    {
        $diff = 'TO_NUMBER(t.trandate - po.custbody41)';
        $plazo = 'TO_NUMBER(po.duedate - po.custbody41)';
        $lead = "TO_NUMBER(NVL(v.custentity6,'0'))";
        $caseCompras =
            "CASE
              WHEN t.trandate < TO_DATE('2023-10-01','YYYY-MM-DD') THEN 0.5
              WHEN t.trandate > TO_DATE('2023-10-01','YYYY-MM-DD') THEN
                CASE WHEN NVL(t.custbody51,'F') = 'T' THEN NULL
                WHEN NVL(po.custbody46,'F') = 'T' THEN NULL
                ELSE CASE WHEN {$diff} <= (CASE WHEN {$plazo} > {$lead} THEN {$plazo} ELSE {$lead} END) THEN 1 ELSE 0 END
                END
              ELSE NULL END";
        $rows = $this->suiteql(
            "SELECT ROUND(AVG({$caseCompras}) * 100, 1) pct, COUNT(*) n"
            .' FROM transaction t'
            .' JOIN nexttransactionlink ntl ON ntl.nextdoc = t.id'
            ." JOIN transaction po ON po.id = ntl.previousdoc AND po.type = 'PurchOrd'"
            .' LEFT JOIN vendor v ON v.id = t.entity'
            ." WHERE t.type = 'ItemRcpt' AND po.customform = 139"
            .' AND po.custbody41 IS NOT NULL'
            ." AND po.trandate >= TO_DATE('2023-01-01','YYYY-MM-DD')"
            ." AND TO_CHAR(t.trandate,'YYYY-MM') = TO_CHAR(CURRENT_DATE,'YYYY-MM')"
        );

        return $this->pickComponent($rows);
    }

    /**
     * DIFOT de entrega — on-time delivery rate for the last complete calendar month.
     * On success always returns a structured array (even when there are 0 evaluables).
     */
    private function computeEntrega(): array
    {
        $now = Carbon::now('America/Argentina/Buenos_Aires');
        $som = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $first = $som->format('d/m/Y');
        $next = $som->copy()->addMonthNoOverflow()->format('d/m/Y');
        $meses = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
            'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

        $rows = $this->suiteql(
            'SELECT COUNT(*) AS total_shipped, '.
            'COUNT(CASE WHEN custbody_tek_fecha_entrega IS NOT NULL THEN 1 END) AS con_real, '.
            'COUNT(CASE WHEN custbody_tek_fecha_entrega IS NOT NULL AND custbody_gep_fecha_entrega_estimado IS NOT NULL THEN 1 END) AS evaluables, '.
            'COUNT(CASE WHEN custbody_tek_fecha_entrega IS NOT NULL AND custbody_gep_fecha_entrega_estimado IS NOT NULL AND custbody_tek_fecha_entrega <= custbody_gep_fecha_entrega_estimado THEN 1 END) AS a_tiempo '.
            'FROM transaction '.
            "WHERE type = 'SalesOrd' ".
            "AND actualshipdate >= TO_DATE('{$first}','DD/MM/YYYY') ".
            "AND actualshipdate < TO_DATE('{$next}','DD/MM/YYYY')"
        );
        if ($rows === null) {
            return ['failed', null];
        }
        $r = $rows[0] ?? [];
        $eval = (int) ($r['evaluables'] ?? 0);
        $ontime = (int) ($r['a_tiempo'] ?? 0);

        return ['ok', [
            'pct' => $eval > 0 ? round($ontime / $eval * 100, 1) : null,
            'n' => $eval,
            'a_tiempo' => $ontime,
            'evaluables' => $eval,
            'con_real' => (int) ($r['con_real'] ?? 0),
            'total_shipped' => (int) ($r['total_shipped'] ?? 0),
            'periodo' => $meses[$som->month],
        ]];
    }

    /** Operaciones DIFOT card — current-month cumplimiento of saved search 674 via the RESTlet. */
    private function computeOperaciones(): array
    {
        $rows = $this->difotSearchRestlet(config('integrations.netsuite.difot.search_id'));
        if ($rows === null) {
            return ['failed', null];
        }

        $month = now()->format('Y-m');
        foreach ($rows as $row) {
            if (($row['Fecha de Entrega'] ?? null) !== $month) {
                continue;
            }
            $pct = (float) str_replace(['%', ','], ['', ''], (string) ($row['CUMPLIMIENTO REAL'] ?? '0'));
            $n = (int) ($row['__cnt'] ?? 0);
            if ($n === 0) {
                return ['ok', null];
            }

            return ['ok', ['pct' => round($pct, 1), 'n' => $n]];
        }

        return ['ok', null];
    }

    /**
     * Map a SuiteQL result to a component value+status.
     * null rows = transport/governance failure (never cache). Zero rows = ok/empty.
     *
     * @return array{0:'ok'|'failed', 1:mixed}
     */
    private function pickComponent(?array $rows): array
    {
        if ($rows === null) {
            return ['failed', null];
        }
        if (! isset($rows[0]) || ($rows[0]['n'] ?? 0) == 0) {
            return ['ok', null];
        }

        return ['ok', ['pct' => (float) ($rows[0]['pct'] ?? 0), 'n' => (int) ($rows[0]['n'] ?? 0)]];
    }

    // ── NetSuite transport (with governance retry/backoff) ───────────────────

    /**
     * SuiteQL query with bounded retry on account-wide governance limits.
     * Returns an array (possibly empty) on success, or null on a hard/exhausted failure.
     */
    private function suiteql(string $sql): ?array
    {
        $attempts = 3;
        for ($i = 0; $i < $attempts; $i++) {
            if ($i > 0) {
                usleep($i * 1_000_000 + random_int(0, 400_000)); // 1s, 2s (+jitter)
            }
            [$rows, $retryable] = $this->suiteqlOnce($sql);
            if ($rows !== null) {
                return $rows;
            }
            if (! $retryable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Run ad-hoc SuiteQL through the "Castle DIFOT Saved Search API" RESTlet
     * (script 1503 / deploy 1) instead of the REST /query/v1/suiteql endpoint.
     * The integration role lacks REST Web Services (SuiteQL) permission, so the
     * direct endpoint returns 401/400; the RESTlet runs N/query.runSuiteQL under
     * the token's role and returns the mapped rows under "rows".
     *
     * @return array{0:?array, 1:bool} [rows|null, retryable]
     */
    private function suiteqlOnce(string $sql): array
    {
        $realm = config('integrations.netsuite.realm');
        $consumerKey = config('integrations.netsuite.restlet.consumer_key');
        $consumerSecret = config('integrations.netsuite.restlet.consumer_secret');
        $token = config('integrations.netsuite.restlet.token');
        $tokenSecret = config('integrations.netsuite.restlet.token_secret');
        $script = config('integrations.netsuite.difot.restlet_script');
        $deploy = config('integrations.netsuite.difot.restlet_deploy');

        $baseUrl = "https://{$realm}.restlets.api.netsuite.com/app/site/hosting/restlet.nl";
        $nonce = bin2hex(random_bytes(11));
        $ts = time();
        $key = rawurlencode($consumerSecret).'&'.rawurlencode($tokenSecret);

        $allParams = [
            'deploy' => $deploy,
            'script' => $script,
            'oauth_consumer_key' => $consumerKey,
            'oauth_nonce' => $nonce,
            'oauth_signature_method' => 'HMAC-SHA256',
            'oauth_timestamp' => $ts,
            'oauth_token' => $token,
            'oauth_version' => '1.0',
        ];
        ksort($allParams);
        $paramStr = http_build_query($allParams, '', '&', PHP_QUERY_RFC3986);
        $baseStr = 'POST&'.rawurlencode($baseUrl).'&'.rawurlencode($paramStr);
        $sig = rawurlencode(base64_encode(hash_hmac('sha256', $baseStr, $key, true)));

        $auth = "OAuth realm=\"{$realm}\","
            ."oauth_consumer_key=\"{$consumerKey}\","
            ."oauth_token=\"{$token}\","
            .'oauth_signature_method="HMAC-SHA256",'
            ."oauth_timestamp=\"{$ts}\","
            ."oauth_nonce=\"{$nonce}\","
            .'oauth_version="1.0",'
            ."oauth_signature=\"{$sig}\"";

        try {
            $response = Http::timeout(45)
                ->withHeaders(['Authorization' => $auth, 'Content-Type' => 'application/json'])
                ->post($baseUrl.'?script='.rawurlencode($script).'&deploy='.rawurlencode($deploy), [
                    'sql' => $sql,
                ]);

            if (! $response->successful()) {
                $retryable = $this->isGovernanceError($response->status(), $response->body());
                Log::error('DIFOT SuiteQL (RESTlet) error', [
                    'status' => $response->status(),
                    'sql' => substr($sql, 0, 120),
                    'body' => substr($response->body(), 0, 300),
                ]);

                return [null, $retryable];
            }
            $json = $response->json();
            if (! is_array($json) || ! array_key_exists('rows', $json)) {
                Log::error('DIFOT SuiteQL (RESTlet) returned no rows', ['body' => substr($response->body(), 0, 300)]);

                return [null, false];
            }

            return [$json['rows'], false];
        } catch (\Exception $e) {
            Log::error('DIFOT SuiteQL (RESTlet) exception', ['msg' => $e->getMessage()]);

            return [null, true]; // transient transport hiccup — worth a retry
        }
    }

    /**
     * Invoke the "Castle DIFOT Saved Search API" RESTlet (script 1503 / deploy 1)
     * for a saved search ID, with bounded retry on governance limits.
     */
    private function difotSearchRestlet(string $searchId): ?array
    {
        $attempts = 3;
        for ($i = 0; $i < $attempts; $i++) {
            if ($i > 0) {
                usleep($i * 1_000_000 + random_int(0, 400_000));
            }
            [$rows, $retryable] = $this->difotSearchRestletOnce($searchId);
            if ($rows !== null) {
                return $rows;
            }
            if (! $retryable) {
                return null;
            }
        }

        return null;
    }

    /** @return array{0:?array, 1:bool} [rows|null, retryable] */
    private function difotSearchRestletOnce(string $searchId): array
    {
        $realm = config('integrations.netsuite.realm');
        $consumerKey = config('integrations.netsuite.restlet.consumer_key');
        $consumerSecret = config('integrations.netsuite.restlet.consumer_secret');
        $token = config('integrations.netsuite.restlet.token');
        $tokenSecret = config('integrations.netsuite.restlet.token_secret');
        $script = config('integrations.netsuite.difot.restlet_script');
        $deploy = config('integrations.netsuite.difot.restlet_deploy');

        $baseUrl = "https://{$realm}.restlets.api.netsuite.com/app/site/hosting/restlet.nl";
        $nonce = bin2hex(random_bytes(11));
        $ts = time();
        $key = rawurlencode($consumerSecret).'&'.rawurlencode($tokenSecret);

        $allParams = [
            'deploy' => $deploy,
            'script' => $script,
            'oauth_consumer_key' => $consumerKey,
            'oauth_nonce' => $nonce,
            'oauth_signature_method' => 'HMAC-SHA256',
            'oauth_timestamp' => $ts,
            'oauth_token' => $token,
            'oauth_version' => '1.0',
        ];
        ksort($allParams);
        $paramStr = http_build_query($allParams, '', '&', PHP_QUERY_RFC3986);
        $baseStr = 'POST&'.rawurlencode($baseUrl).'&'.rawurlencode($paramStr);
        $sig = rawurlencode(base64_encode(hash_hmac('sha256', $baseStr, $key, true)));

        $auth = "OAuth realm=\"{$realm}\","
            ."oauth_consumer_key=\"{$consumerKey}\","
            ."oauth_token=\"{$token}\","
            .'oauth_signature_method="HMAC-SHA256",'
            ."oauth_timestamp=\"{$ts}\","
            ."oauth_nonce=\"{$nonce}\","
            .'oauth_version="1.0",'
            ."oauth_signature=\"{$sig}\"";

        try {
            $response = Http::timeout(45)
                ->withHeaders(['Authorization' => $auth, 'Content-Type' => 'application/json'])
                ->post($baseUrl.'?script='.rawurlencode($script).'&deploy='.rawurlencode($deploy), [
                    'searchID' => $searchId,
                ]);

            if (! $response->successful()) {
                $retryable = $this->isGovernanceError($response->status(), $response->body());
                Log::error('DIFOT RESTlet HTTP error', ['status' => $response->status(), 'body' => substr($response->body(), 0, 300)]);

                return [null, $retryable];
            }
            $json = $response->json();
            if (! isset($json['results'])) {
                Log::error('DIFOT RESTlet returned no results', ['body' => substr($response->body(), 0, 300)]);

                return [null, false];
            }

            return [$json['results'], false];
        } catch (\Exception $e) {
            Log::error('DIFOT RESTlet exception', ['msg' => $e->getMessage()]);

            return [null, true];
        }
    }

    /** True when the response is an account-wide NetSuite governance limit (worth retrying). */
    private function isGovernanceError(int $status, string $body): bool
    {
        if ($status === 429) {
            return true;
        }

        return str_contains($body, 'CONCURRENCY_LIMIT_EXCEEDED')
            || str_contains($body, 'SSS_REQUEST_LIMIT_EXCEEDED')
            || str_contains($body, 'REQUEST_LIMIT_EXCEEDED');
    }
}
