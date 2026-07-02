<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Operaciones — Castle</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: #0d1117;
            color: #e6edf3;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
            min-height: 100vh;
            padding: 24px;
        }
        .header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 28px; flex-wrap: wrap; gap: 12px;
        }
        .header h1 { font-size: 22px; font-weight: 600; color: #f0f6fc; }
        .header-meta {
            display: flex; align-items: center; gap: 10px;
            font-size: 13px; color: #8b949e; flex-wrap: wrap;
        }
        .nav-link {
            color: #79c0ff; text-decoration: none; font-size: 13px;
            border: 1px solid #30363d; padding: 7px 14px; border-radius: 6px;
        }
        .nav-link:hover { background: #21262d; }
        .refresh-btn {
            background: #21262d; border: 1px solid #30363d; color: #e6edf3;
            padding: 7px 14px; border-radius: 6px; font-size: 13px; cursor: pointer;
            transition: background 0.15s; white-space: nowrap;
        }
        .refresh-btn:hover { background: #30363d; }
        .section-title {
            font-size: 12px; color: #8b949e; text-transform: uppercase; letter-spacing: 0.05em;
            margin-bottom: 12px;
        }
        .stats-row {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 14px; margin-bottom: 24px;
        }
        .stat-card {
            background: #161b22; border: 1px solid #30363d;
            border-radius: 8px; padding: 16px 20px;
        }
        .stat-card.overdue { border-color: #f8514944; }
        .stat-card.ontime  { border-color: #3fb95044; }
        .stat-card .label {
            font-size: 11px; color: #8b949e; text-transform: uppercase; letter-spacing: 0.05em;
        }
        .stat-card .value { font-size: 26px; font-weight: 600; color: #f0f6fc; margin-top: 6px; }
        .stat-card.total .value { color: #adb5bd; }
        .stat-card.overdue .value { color: #ff7b72; }
        .stat-card.ontime  .value { color: #3fb950; }
        .stat-card .sub { font-size: 11px; color: #8b949e; margin-top: 4px; }
        .panel {
            background: #161b22; border: 1px solid #30363d; border-radius: 8px;
            padding: 20px; margin-bottom: 24px;
        }
        .panel h2 { font-size: 15px; font-weight: 600; color: #f0f6fc; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 9px 12px; text-align: right; border-bottom: 1px solid #21262d; }
        th:first-child, td:first-child { text-align: left; }
        thead th { color: #8b949e; font-weight: 500; text-transform: uppercase; font-size: 11px; letter-spacing: 0.04em; }
        tbody tr:hover { background: #1c2128; }
        .num-overdue { color: #ff7b72; }
        .num-ontime  { color: #3fb950; }
        tfoot td { font-weight: 600; color: #f0f6fc; border-top: 2px solid #30363d; }
        .error-box {
            background: #2d1517; border: 1px solid #f8514955; color: #ff9d96;
            padding: 16px 20px; border-radius: 8px;
        }
        .muted { color: #8b949e; }
    </style>
</head>
<body>
@php
    $fmt = fn($n) => '$' . number_format((float) $n, 0);
    $pctFmt = fn($p) => ($p == round($p) ? number_format($p, 0) : number_format($p, 1)) . '%';
    $difotColor = fn($p) => $p >= 95 ? '#3fb950' : ($p >= 85 ? '#d29922' : '#ff7b72');
@endphp
<div class="header">
    <h1>Operaciones — Entregas</h1>
    <div class="header-meta">
        @if($fetchedAt)
            <span>Actualizado: {{ \Carbon\Carbon::parse($fetchedAt)->locale('es')->isoFormat('HH:mm') }}</span>
        @endif
        <a class="nav-link" href="/ventas">← Ventas</a>
        <button class="refresh-btn" onclick="location.reload()">Actualizar</button>
    </div>
</div>

<div class="section-title">DIFOT — Cumplimiento (mes actual)</div>
<div class="stats-row">
    @php
        $difotCards = [
            ['Producción',    $difot['produccion']  ?? null],
            ['Cinta Impresa', $difot['cinta']       ?? null],
            ['Operaciones',   $difot['operaciones'] ?? null],
            ['Compras',       $difot['compras']     ?? null],
        ];
    @endphp
    @foreach($difotCards as [$label, $data])
        <div class="stat-card">
            <div class="label">DIFOT {{ $label }}</div>
            @if($data)
                <div class="value" style="color: {{ $difotColor($data['pct']) }}">{{ $pctFmt($data['pct']) }}</div>
                <div class="sub">{{ $data['n'] }} líneas · mes actual</div>
            @else
                <div class="value" style="color:#8b949e">—</div>
                <div class="sub">Pendiente</div>
            @endif
        </div>
    @endforeach
    @php $e = $difot['entrega'] ?? null; @endphp
    <div class="stat-card">
        <div class="label">DIFOT Entrega</div>
        @if($e && !is_null($e['pct']))
            <div class="value" style="color: {{ $difotColor($e['pct']) }}">{{ $pctFmt($e['pct']) }}</div>
            <div class="sub">{{ $e['a_tiempo'] }}/{{ $e['evaluables'] }} a tiempo · {{ $e['periodo'] }}</div>
            <div class="sub" style="color:#6e7681">{{ $e['con_real'] }}/{{ $e['total_shipped'] }} con fecha real ({{ $e['total_shipped'] > 0 ? round($e['con_real'] / $e['total_shipped'] * 100, 1) : 0 }}%)</div>
        @else
            <div class="value" style="color:#8b949e">—</div>
            <div class="sub">Pendiente</div>
        @endif
    </div>
</div>

@if($totals === null)
    <div class="error-box">
        No se pudieron obtener los datos de NetSuite. Reintentá en unos segundos o revisá la conexión.
    </div>
@else
    @php
        $overdue = $totals['overdue']['amount'];
        $ontime  = $totals['ontime']['amount'];
        $grand   = $overdue + $ontime;
        $pctOverdue = $grand > 0 ? round($overdue / $grand * 100) : 0;
    @endphp
    <div class="section-title">Monto por entregar</div>
    <div class="stats-row">
        <div class="stat-card total">
            <div class="label">Monto por entregar</div>
            <div class="value">{{ $fmt($grand) }}</div>
            <div class="sub">USD · {{ $totals['overdue']['orders'] + $totals['ontime']['orders'] }} líneas-pedido abiertas</div>
        </div>
        <div class="stat-card overdue">
            <div class="label">Vencido</div>
            <div class="value">{{ $fmt($overdue) }}</div>
            <div class="sub">Pasó la fecha de envío · {{ $pctOverdue }}% del total</div>
        </div>
        <div class="stat-card ontime">
            <div class="label">En plazo</div>
            <div class="value">{{ $fmt($ontime) }}</div>
            <div class="sub">Dentro del plazo de entrega · {{ 100 - $pctOverdue }}% del total</div>
        </div>
    </div>

    <div class="panel">
        <h2>Detalle por representante</h2>
        @if(count($byRep))
        <table>
            <thead>
                <tr>
                    <th>Representante</th>
                    <th>Vencido</th>
                    <th>En plazo</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($byRep as $r)
                <tr>
                    <td>{{ $r['rep'] }}</td>
                    <td class="num-overdue">{{ $fmt($r['overdue']) }}</td>
                    <td class="num-ontime">{{ $fmt($r['ontime']) }}</td>
                    <td>{{ $fmt($r['overdue'] + $r['ontime']) }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td>Total</td>
                    <td class="num-overdue">{{ $fmt($overdue) }}</td>
                    <td class="num-ontime">{{ $fmt($ontime) }}</td>
                    <td>{{ $fmt($grand) }}</td>
                </tr>
            </tfoot>
        </table>
        @else
            <p class="muted">Sin datos por representante.</p>
        @endif
    </div>
@endif

<p class="muted" style="font-size:11px">
    DIFOT del mes actual, reproducido en SuiteQL desde las búsquedas
    GEP - DIFOT … - KPI (Producción = búsqueda 761, Cinta Impresa = 1504, Compras = 1338).
    Monto por entregar: órdenes de venta abiertas en NetSuite (USD), búsqueda 805.
</p>
</body>
</html>
