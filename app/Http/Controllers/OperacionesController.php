<?php

namespace App\Http\Controllers;

use App\Services\DifotService;

/**
 * Operaciones dashboard (/operaciones).
 *
 * Task 021 — splits the value of open sales orders still to deliver ("monto por
 * entregar") into what is already past its promised delivery date (vencido) vs
 * what is still within the delivery timeframe (en plazo), using the promised ship
 * date custom field custcol_3k_fecha_envio_cumplimiento ("Fecha de envio").
 *
 * The delivery-split totals + per-rep breakdown are served from DifotService's DB
 * cache with stale fallback (same contract as the DIFOT cards), so a transient
 * NetSuite failure keeps the last-good values visible instead of blanking the whole
 * section. See DifotService::deliverySplit().
 *
 * Task 022 — DIFOT (Delivery In Full On Time) cards, also served from DifotService.
 */
class OperacionesController extends Controller
{
    public function index(DifotService $difot)
    {
        $split = $difot->deliverySplit();

        return view('operaciones', [
            'totals' => $split['totals'],
            'byRep' => $split['byRep'],
            'difot' => $difot->cards(),
            'fetchedAt' => $split['fetchedAt'],
        ]);
    }
}
