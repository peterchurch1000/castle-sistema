<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Ventas Castle</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 28px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .header h1 {
            font-size: 22px;
            font-weight: 600;
            color: #f0f6fc;
        }

        .header-meta {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            color: #8b949e;
            flex-wrap: wrap;
        }

        .refresh-btn {
            background: #21262d;
            border: 1px solid #30363d;
            color: #e6edf3;
            padding: 7px 14px;
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            transition: background 0.15s;
            white-space: nowrap;
        }
        .refresh-btn:hover { background: #30363d; }
        .refresh-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .refresh-btn.activities {
            border-color: #388bfd44;
            color: #79c0ff;
        }
        .refresh-btn.activities:hover { background: #388bfd22; }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 14px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: #161b22;
            border: 1px solid #30363d;
            border-radius: 8px;
            padding: 14px 18px;
        }

        .stat-card .label {
            font-size: 11px;
            color: #8b949e;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .stat-card .stat-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 8px;
        }
        .stat-card .stat-row + .stat-row { margin-top: 5px; }

        .stat-card .value {
            font-size: 22px;
            font-weight: 600;
            color: #f0f6fc;
        }

        .stat-card .gp-label { color: #3fb950; }
        .stat-card .gp-val {
            font-size: 18px;
            font-weight: 600;
            color: #3fb950;
            white-space: nowrap;
        }

        .stat-card .sub {
            font-size: 11px;
            color: #8b949e;
            margin-top: 3px;
        }

        /* Side-by-side charts */
        .charts-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        @media (max-width: 900px) {
            .charts-row { grid-template-columns: 1fr; }
        }

        .chart-card {
            background: #161b22;
            border: 1px solid #30363d;
            border-radius: 8px;
            padding: 20px;
        }

        .chart-header {
            text-align: center;
            margin-bottom: 14px;
        }

        .chart-title {
            font-size: 56px;
            font-weight: 600;
            color: #f0f6fc;
        }

        .legend {
            display: flex;
            gap: 16px;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 11px;
            color: #8b949e;
        }

        .legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 2px;
            flex-shrink: 0;
        }

        .no-data {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 200px;
            color: #8b949e;
            font-size: 13px;
        }

        canvas { display: block; }

        .toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: #21262d;
            border: 1px solid #30363d;
            border-radius: 8px;
            padding: 12px 20px;
            font-size: 13px;
            opacity: 0;
            transform: translateY(8px);
            transition: all 0.2s;
            pointer-events: none;
            z-index: 100;
        }
        .toast.show { opacity: 1; transform: translateY(0); }
        .toast.error { border-color: #f85149; color: #f85149; }
        .toast.success { border-color: #3fb950; color: #3fb950; }
    </style>
</head>
<body>

@php
    $meses = ['','enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    $mesNombre  = ucfirst($meses[$month]);
    $totalSales    = $chartData->sum('sales');
    $totalSold     = $chartData->sum('sold');
    $totalSoldNext = $chartData->sum('soldNext');
    $totalPending  = $chartData->sum('pending');
    $mesProx       = ucfirst($meses[$month == 12 ? 1 : $month + 1]);
    $totalQuota   = $chartData->sum('quota');
    $totalPace    = $chartData->sum('pace');
    $totalAll     = $totalSales + $totalSold + $totalPending;
    $pacePercent  = round($paceRatio * 100, 1);

    $totalScore   = $activityChartData->sum('score');
    $totalTarget  = $activityChartData->sum('target');
    $actAttain    = $totalTarget > 0 ? round($totalScore / $totalTarget * 100, 1) : 0;
    $hasActivity  = $activityChartData->sum('score') > 0 || $activityChartData->sum('target') > 0;

    // GP % per card (saved search 422 "% GP APROX"); null → not shown
    $gpFmt = fn($k) => !is_null($gp->get($k)) ? number_format($gp->get($k), 1) : null;
    $pipFmt = fn($n) => '$' . number_format($n / 1000, 1) . 'k';
@endphp

<div class="header">
    <h1>Panel Castle — {{ $mesNombre }} {{ $year }}</h1>
    <div class="header-meta">
        @if($lastRefreshed)
            <span>Ventas: {{ \Carbon\Carbon::parse($lastRefreshed)->locale('es')->diffForHumans() }}</span>
        @endif
        @if($quotaLastRefreshed)
            <span>Cuotas: {{ \Carbon\Carbon::parse($quotaLastRefreshed)->locale('es')->diffForHumans() }}</span>
        @endif
        @if($activityLastRefreshed)
            <span>Actividades: {{ \Carbon\Carbon::parse($activityLastRefreshed)->locale('es')->diffForHumans() }}</span>
        @endif
    </div>
</div>

<div class="stats-row">
    <div class="stat-card">
        <div class="stat-row"><span class="label">Ingresos Previstos</span></div>
        <div class="stat-row">
            <span class="value" style="color: #58a6ff">{{ $pipeline ? $pipFmt($pipeline['previstos']['amt']) : '—' }}</span>
        </div>
        <div class="sub">{{ $pipeline ? $pipeline['previstos']['cnt'] . ' oportunidades · cierre este mes' : 'Salesforce no disponible' }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-row"><span class="label">Pipeline Total</span></div>
        <div class="stat-row">
            <span class="value" style="color: #58a6ff">{{ $pipeline ? $pipFmt($pipeline['pipeline']['amt']) : '—' }}</span>
        </div>
        <div class="sub">{{ $pipeline ? $pipeline['pipeline']['cnt'] . ' oportunidades abiertas' : 'Salesforce no disponible' }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-row">
            <span class="label">Ventas MTD</span>
            @if($gpFmt('invoiced'))<span class="label gp-label">GP%</span>@endif
        </div>
        <div class="stat-row">
            <span class="value">${{ number_format($totalSales / 1000, 1) }}k</span>
            @if($gpFmt('invoiced'))<span class="gp-val">{{ $gpFmt('invoiced') }}</span>@endif
        </div>
        <div class="sub">USD facturado</div>
    </div>
    <div class="stat-card">
        <div class="stat-row">
            <span class="label">Vendido no facturado</span>
            @if($gpFmt('sold'))<span class="label gp-label">GP%</span>@endif
        </div>
        <div class="stat-row">
            <span class="value" style="color: #adb5bd">${{ number_format($totalSold / 1000, 1) }}k</span>
            @if($gpFmt('sold'))<span class="gp-val">{{ $gpFmt('sold') }}</span>@endif
        </div>
        <div class="sub">USD zona gris</div>
    </div>
    <div class="stat-card">
        <div class="stat-row">
            <span class="label">Remito por facturar</span>
            @if($gpFmt('pending'))<span class="label gp-label">GP%</span>@endif
        </div>
        <div class="stat-row">
            <span class="value" style="color: #db6d28">${{ number_format($totalPending / 1000, 1) }}k</span>
            @if($gpFmt('pending'))<span class="gp-val">{{ $gpFmt('pending') }}</span>@endif
        </div>
        <div class="sub">USD entregado s/ facturar</div>
    </div>
    <div class="stat-card">
        <div class="stat-row">
            <span class="label">Total General</span>
            @if($gpFmt('total'))<span class="label gp-label">GP%</span>@endif
        </div>
        <div class="stat-row">
            <span class="value">${{ number_format($totalAll / 1000, 1) }}k</span>
            @if($gpFmt('total'))<span class="gp-val">{{ $gpFmt('total') }}</span>@endif
        </div>
        <div class="sub">fact. + gris + remito</div>
    </div>
    <div class="stat-card">
        <div class="stat-row">
            <span class="label">Objetivo Ventas</span>
        </div>
        <div class="stat-row">
            <span class="value">${{ number_format($totalQuota / 1000, 1) }}k</span>
        </div>
        <div class="sub">cuota mensual</div>
    </div>
    <div class="stat-card">
        <div class="stat-row">
            <span class="label">Vendido no facturado ({{ $mesProx }})</span>
            @if($gpFmt('sold_next'))<span class="label gp-label">GP%</span>@endif
        </div>
        <div class="stat-row">
            <span class="value" style="color: #adb5bd">${{ number_format($totalSoldNext / 1000, 1) }}k</span>
            @if($gpFmt('sold_next'))<span class="gp-val">{{ $gpFmt('sold_next') }}</span>@endif
        </div>
        <div class="sub">USD zona gris próx. mes</div>
    </div>
    <div class="stat-card">
        <div class="stat-row"><span class="label">Score Actividades</span></div>
        <div class="stat-row">
            <span class="value" style="color: {{ $actAttain >= $pacePercent ? '#3fb950' : ($hasActivity ? '#f85149' : '#8b949e') }}">
                {{ $hasActivity ? $actAttain . '%' : '—' }}
            </span>
        </div>
        <div class="sub">{{ $hasActivity ? 'ritmo: ' . $pacePercent . '%' : 'sin datos aún' }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-row"><span class="label">Ritmo del Mes</span></div>
        <div class="stat-row">
            <span class="value">${{ number_format($totalPace / 1000, 1) }}k</span>
        </div>
        <div class="sub">{{ $pacePercent }}% transcurrido</div>
    </div>
</div>

<div class="charts-row">
    {{-- Activities chart (left) --}}
    <div class="chart-card">
        <div class="chart-header">
            <div class="chart-title">Actividades</div>
        </div>
        @if($hasActivity)
        <div class="legend">
            <div class="legend-item"><div class="legend-dot" style="background:#3fb950"></div> A ritmo / Por encima</div>
            <div class="legend-item"><div class="legend-dot" style="background:#f85149"></div> Por debajo</div>
            <div class="legend-item" style="align-items:center">
                <div style="width:16px;height:2px;background:#f0c040;border-top:2px dashed #f0c040;margin-right:5px;"></div>
                Ritmo ({{ $pacePercent }}%)
            </div>
        </div>
        <div id="actChartWrapper" style="position:relative;">
            <canvas id="actChart"></canvas>
        </div>
        @else
        <div class="no-data">
            Sin datos todavía — se actualizará automáticamente
        </div>
        @endif
    </div>

    {{-- Sales chart (right) --}}
    <div class="chart-card">
        <div class="chart-header">
            <div class="chart-title">Ventas</div>
        </div>
        <div class="legend">
            <div class="legend-item"><div class="legend-dot" style="background:#3fb950"></div> A ritmo / Por encima</div>
            <div class="legend-item"><div class="legend-dot" style="background:#f85149"></div> Por debajo</div>
            <div class="legend-item"><div class="legend-dot" style="background:#6e7681"></div> Vendido no facturado</div>
            <div class="legend-item"><div class="legend-dot" style="background:#db6d28"></div> Remito por facturar</div>
            <div class="legend-item" style="align-items:center">
                <div style="width:16px;height:2px;background:#f0c040;border-top:2px dashed #f0c040;margin-right:5px;"></div>
                Ritmo ({{ $pacePercent }}%)
            </div>
        </div>
        <div id="salesChartWrapper" style="position:relative;">
            <canvas id="salesChart"></canvas>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
const chartData         = @json($chartData);
const activityChartData = @json($activityChartData);
const pacePercent       = {{ $pacePercent }};
const hasActivity       = {{ $hasActivity ? 'true' : 'false' }};

// ─── Shared plugins ───────────────────────────────────────────────────────────

function makePaceLinePlugin(pace) {
    return {
        id: 'paceLine_' + Math.random(),
        afterDraw(chart) {
            const { ctx, chartArea: { top, bottom }, scales: { x } } = chart;
            const xPos = x.getPixelForValue(pace);
            ctx.save();
            ctx.beginPath();
            ctx.moveTo(xPos, top);
            ctx.lineTo(xPos, bottom);
            ctx.strokeStyle = '#f0c040';
            ctx.lineWidth = 2;
            ctx.setLineDash([6, 3]);
            ctx.stroke();
            ctx.fillStyle = '#f0c040';
            ctx.font = '22px system-ui, sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('Ritmo', xPos, top - 6);
            ctx.restore();
        }
    };
}

function makeQuotaLabelsPlugin(data, labelFn) {
    return {
        id: 'quotaLabels_' + Math.random(),
        afterDraw(chart) {
            const { ctx, chartArea: { right }, scales: { y } } = chart;
            ctx.save();
            ctx.fillStyle = '#8b949e';
            ctx.font = '22px system-ui, sans-serif';
            ctx.textAlign = 'left';
            data.forEach((d, i) => {
                const yPos = y.getPixelForValue(i);
                ctx.fillText(labelFn(d), right + 6, yPos + 4);
            });
            ctx.restore();
        }
    };
}

function makeBarValuesPlugin(data, valueFn, percentData) {
    return {
        id: 'barValues_' + Math.random(),
        afterDatasetsDraw(chart) {
            const { ctx, scales: { x, y } } = chart;
            ctx.save();
            ctx.font = '22px system-ui, sans-serif';
            data.forEach((d, i) => {
                const label = valueFn(d);
                if (!label) return;
                const val  = percentData[i];
                const xPos = x.getPixelForValue(val);
                const x0   = x.getPixelForValue(0);
                const yPos = y.getPixelForValue(i);
                const barW = xPos - x0;
                const tw   = ctx.measureText(label).width;
                if (barW > tw + 20) {
                    ctx.fillStyle = 'rgba(13,17,23,0.85)';
                    ctx.textAlign = 'right';
                    ctx.fillText(label, xPos - 6, yPos + 4);
                } else {
                    ctx.fillStyle = '#8b949e';
                    ctx.textAlign = 'left';
                    ctx.fillText(label, xPos + 6, yPos + 4);
                }
            });
            ctx.restore();
        }
    };
}

// Draws the invoiced ($ green/red), sold ($ grey) and pending ($ orange) value labels for
// the Ventas chart, coordinated so they never overlap on narrow bars.
function makeSalesValuesPlugin(data, invoicedPctData, soldPctData, pendingPctData) {
    return {
        id: 'salesValues_' + Math.random(),
        afterDatasetsDraw(chart) {
            const { ctx, scales: { x, y } } = chart;
            ctx.save();
            ctx.font = '22px system-ui, sans-serif';
            data.forEach((d, i) => {
                const yPos  = y.getPixelForValue(i);
                const x0    = x.getPixelForValue(0);
                const xInv  = x.getPixelForValue(invoicedPctData[i]);
                const xSold = x.getPixelForValue(invoicedPctData[i] + soldPctData[i]);
                const xPend = x.getPixelForValue(invoicedPctData[i] + soldPctData[i] + pendingPctData[i]);

                const invLabel = (invoicedPctData[i] > 0 || (d.sales || 0) > 0)
                    ? '$' + ((d.sales || 0) / 1000).toFixed(1) + 'k' : '';
                const sLabel = (d.sold || 0) > 0 ? '$' + ((d.sold || 0) / 1000).toFixed(1) + 'k' : '';
                const pLabel = (d.pending || 0) > 0 ? '$' + ((d.pending || 0) / 1000).toFixed(1) + 'k' : '';

                // Stagger vertically when more than one label is present so they never overlap
                // on narrow bars (segments end at different x but can sit close). Centre a lone label.
                const present = [invLabel, sLabel, pLabel].filter(Boolean).length;
                const offsets = present <= 1 ? [4] : present === 2 ? [-8, 16] : [-12, 8, 28];
                let slot = 0;
                const nextY = () => yPos + offsets[slot++];

                const drawLabel = (label, xEnd, xStart, insideColor, outsideColor) => {
                    if (!label) return;
                    const tw = ctx.measureText(label).width;
                    const yy = nextY();
                    if (xEnd - xStart > tw + 16) {
                        ctx.fillStyle = insideColor;
                        ctx.textAlign = 'right';
                        ctx.fillText(label, xEnd - 6, yy);
                    } else {
                        ctx.fillStyle = outsideColor;
                        ctx.textAlign = 'left';
                        ctx.fillText(label, xEnd + 6, yy);
                    }
                };

                drawLabel(invLabel, xInv,  x0,    'rgba(13,17,23,0.85)', '#c9d1d9');
                drawLabel(sLabel,   xSold, xInv,  '#f0f6fc',             '#8b949e');
                drawLabel(pLabel,   xPend, xSold, '#0d1117',             '#db6d28');
            });
            ctx.restore();
        }
    };
}

function buildHBarChart(canvasId, wrapperId, labels, percentData, barColors, maxPct, tooltipFn, quotaLabelFn, barValueLabelFn, plugins, yTickOptions, extraDatasets) {
    const ctx = document.getElementById(canvasId).getContext('2d');
    const yTicks = yTickOptions !== undefined ? yTickOptions : { color: '#e6edf3', font: { size: 41, weight: 'bold' } };
    const extra = extraDatasets ? (Array.isArray(extraDatasets) ? extraDatasets : [extraDatasets]) : [];
    const stacked = extra.length > 0;
    const datasets = [{
        label: 'Cumplimiento',
        data: percentData,
        backgroundColor: barColors,
        borderRadius: 4,
        borderSkipped: false,
    }, ...extra];
    const chart = new Chart(ctx, {
        type: 'bar',
        data: { labels, datasets },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            layout: { padding: { right: 96, top: 18 } },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: { label: tooltipFn },
                    backgroundColor: '#21262d',
                    borderColor: '#30363d',
                    borderWidth: 1,
                    titleColor: '#f0f6fc',
                    bodyColor: '#8b949e',
                }
            },
            scales: {
                x: {
                    min: 0, max: maxPct,
                    stacked,
                    grid: { color: 'rgba(48,54,61,0.5)' },
                    ticks: { color: '#8b949e', callback: v => Math.round(v) + '%' },
                    border: { color: '#30363d' }
                },
                y: {
                    stacked,
                    grid: { display: false },
                    ticks: yTicks,
                    border: { color: '#30363d' }
                }
            }
        },
        plugins
    });

    const height = Math.max(260, labels.length * 85 + 40);
    document.getElementById(wrapperId).style.height = height + 'px';
    chart.resize();
    return chart;
}

// ─── Activities chart (left) ──────────────────────────────────────────────────

if (hasActivity) {
    const actLabels  = activityChartData.map(d => d.rep.split(' ')[0]);
    const actPercent = activityChartData.map(d => d.target > 0 ? Math.round(d.score / d.target * 1000) / 10 : 0);
    const actColors  = activityChartData.map(d => d.score >= d.pace ? '#3fb950' : '#f85149');
    const actMax     = Math.max(110, ...actPercent.map(v => v + 10));

    buildHBarChart(
        'actChart', 'actChartWrapper',
        actLabels, actPercent, actColors, actMax,
        ctx => {
            const d = activityChartData[ctx.dataIndex];
            return [
                ` ${ctx.parsed.x.toFixed(1)}% del objetivo`,
                ` Score: ${d.score} pts (${d.emails}✉ ${d.calls}☎ ${d.visits}🤝)`,
                `   ✉ incluye ${d.whatsapp} WhatsApp + ${d.linkedin} LinkedIn`,
                ` Objetivo: ${d.target > 0 ? d.target + ' pts' : 'Sin objetivo'}`,
            ];
        },
        d => d.target > 0 ? d.target + ' pts' : 'sin obj',
        null,
        [
            makePaceLinePlugin(pacePercent),
            makeQuotaLabelsPlugin(activityChartData, d => d.target > 0 ? d.target + ' pts' : 'sin obj'),
            makeBarValuesPlugin(activityChartData, d => d.score > 0 ? d.score + ' pts' : '', actPercent),
        ],
        { color: '#e6edf3', font: { size: 41, weight: 'bold' } }
    );
}

// ─── Sales chart (right) ──────────────────────────────────────────────────────

const salesLabels   = chartData.map(d => d.rep);
const salesPercent  = chartData.map(d => d.quota > 0 ? Math.round(d.sales / d.quota * 1000) / 10 : 0);
const soldPercent   = chartData.map(d => d.quota > 0 ? Math.round((d.sold || 0) / d.quota * 1000) / 10 : 0);
const pendingPercent = chartData.map(d => d.quota > 0 ? Math.round((d.pending || 0) / d.quota * 1000) / 10 : 0);
const salesColors   = chartData.map(d => d.sales >= d.pace ? '#3fb950' : '#f85149');
const salesMax      = Math.max(110, ...chartData.map((d, i) => salesPercent[i] + soldPercent[i] + pendingPercent[i] + 10));

// Grey "zona gris" segment: sold but not yet invoiced (open sales orders), stacked on top
const soldDataset = {
    label: 'Vendido no facturado',
    data: soldPercent,
    backgroundColor: '#6e7681',
    borderRadius: 4,
    borderSkipped: false,
};

// Orange "remito por facturar" segment: delivered but not yet invoiced, stacked after grey
const pendingDataset = {
    label: 'Remito por facturar',
    data: pendingPercent,
    backgroundColor: '#db6d28',
    borderRadius: 4,
    borderSkipped: false,
};

buildHBarChart(
    'salesChart', 'salesChartWrapper',
    salesLabels, salesPercent, salesColors, salesMax,
    ctx => {
        const d = chartData[ctx.dataIndex];
        if (ctx.datasetIndex === 1) {
            return [
                ` Vendido no facturado: $${(d.sold || 0).toLocaleString('en-US', {maximumFractionDigits:0})}`,
                ` (${ctx.parsed.x.toFixed(1)}% del objetivo)`,
            ];
        }
        if (ctx.datasetIndex === 2) {
            return [
                ` Remito por facturar: $${(d.pending || 0).toLocaleString('en-US', {maximumFractionDigits:0})}`,
                ` (${ctx.parsed.x.toFixed(1)}% del objetivo)`,
            ];
        }
        return [
            ` ${ctx.parsed.x.toFixed(1)}% del objetivo`,
            ` Facturado: $${d.sales.toLocaleString('en-US', {maximumFractionDigits:0})}`,
            ` Objetivo: ${d.quota > 0 ? '$' + d.quota.toLocaleString('en-US') : 'Sin cuota'}`,
        ];
    },
    d => d.quota > 0 ? '$' + (d.quota / 1000).toFixed(0) + 'k obj' : 'sin cuota',
    (d, i) => salesPercent[i] > 0 ? '$' + (d.sales / 1000).toFixed(1) + 'k' : null,
    [
        makePaceLinePlugin(pacePercent),
        makeQuotaLabelsPlugin(chartData, d => d.quota > 0 ? '$' + (d.quota/1000).toFixed(0) + 'k obj' : 'sin cuota'),
        makeSalesValuesPlugin(chartData, salesPercent, soldPercent, pendingPercent),
    ],
    { display: false },
    [soldDataset, pendingDataset]
);

// ─── Auto-reload ──────────────────────────────────────────────────────────────
// Data is refreshed server-side by the scheduler (cron → castle:refresh):
// every 5 min during Argentine business hours, every 30 min off-hours.
// Here we just reload the page periodically so an open dashboard shows the
// latest figures without anyone clicking anything.
setTimeout(() => location.reload(), 5 * 60 * 1000);
</script>

</body>
</html>
