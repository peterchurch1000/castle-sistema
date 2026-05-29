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
        }

        .header h1 {
            font-size: 22px;
            font-weight: 600;
            color: #f0f6fc;
        }

        .header-meta {
            display: flex;
            align-items: center;
            gap: 16px;
            font-size: 13px;
            color: #8b949e;
        }

        .refresh-btn {
            background: #21262d;
            border: 1px solid #30363d;
            color: #e6edf3;
            padding: 7px 16px;
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            transition: background 0.15s;
        }
        .refresh-btn:hover { background: #30363d; }
        .refresh-btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: #161b22;
            border: 1px solid #30363d;
            border-radius: 8px;
            padding: 16px 20px;
        }

        .stat-card .label {
            font-size: 12px;
            color: #8b949e;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 6px;
        }

        .stat-card .value {
            font-size: 24px;
            font-weight: 600;
            color: #f0f6fc;
        }

        .stat-card .sub {
            font-size: 12px;
            color: #8b949e;
            margin-top: 4px;
        }

        .chart-card {
            background: #161b22;
            border: 1px solid #30363d;
            border-radius: 8px;
            padding: 24px;
        }

        .chart-title {
            font-size: 15px;
            font-weight: 600;
            color: #f0f6fc;
            margin-bottom: 20px;
        }

        .legend {
            display: flex;
            gap: 20px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: #8b949e;
        }

        .legend-dot {
            width: 12px;
            height: 12px;
            border-radius: 2px;
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
        }
        .toast.show { opacity: 1; transform: translateY(0); }
        .toast.error { border-color: #f85149; color: #f85149; }
        .toast.success { border-color: #3fb950; color: #3fb950; }
    </style>
</head>
<body>

@php
    $meses = ['','enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    $mesNombre = ucfirst($meses[$month]);
    $totalSales = $chartData->sum('sales');
    $totalQuota = $chartData->sum('quota');
    $totalPace  = $chartData->sum('pace');
    $pacePercent = round($paceRatio * 100, 1);
    $attainment  = $totalQuota > 0 ? round($totalSales / $totalQuota * 100, 1) : 0;
@endphp

<div class="header">
    <h1>Rendimiento de Ventas — {{ $mesNombre }} {{ $year }}</h1>
    <div class="header-meta">
        @if($lastRefreshed)
            <span>Actualizado: {{ \Carbon\Carbon::parse($lastRefreshed)->locale('es')->diffForHumans() }}</span>
        @else
            <span>Sin datos — haz clic en Actualizar</span>
        @endif
        <button class="refresh-btn" id="refreshBtn" onclick="refreshData()">&#8635; Actualizar desde NetSuite</button>
    </div>
</div>

<div class="stats-row">
    <div class="stat-card">
        <div class="label">Ventas MTD Totales</div>
        <div class="value">${{ number_format($totalSales / 1000, 1) }}k</div>
        <div class="sub">USD este mes</div>
    </div>
    <div class="stat-card">
        <div class="label">Objetivo Total</div>
        <div class="value">${{ number_format($totalQuota / 1000, 1) }}k</div>
        <div class="sub">cuota mensual</div>
    </div>
    <div class="stat-card">
        <div class="label">Objetivo de Ritmo</div>
        <div class="value">${{ number_format($totalPace / 1000, 1) }}k</div>
        <div class="sub">{{ $pacePercent }}% del mes transcurrido</div>
    </div>
    <div class="stat-card">
        <div class="label">Cumplimiento del Equipo</div>
        <div class="value" style="color: {{ $attainment >= $pacePercent ? '#3fb950' : '#f85149' }}">{{ $attainment }}%</div>
        <div class="sub">vs {{ $pacePercent }}% de ritmo</div>
    </div>
</div>

<div class="chart-card">
    <div class="chart-title">Rendimiento de Representantes vs Objetivo</div>
    <div class="legend">
        <div class="legend-item"><div class="legend-dot" style="background:#3fb950"></div> A ritmo / Por encima</div>
        <div class="legend-item"><div class="legend-dot" style="background:#f85149"></div> Por debajo del ritmo</div>
        <div class="legend-item" style="align-items:center">
            <div style="width:18px;height:2px;background:#f0c040;border-top:2px dashed #f0c040;margin-right:6px;"></div>
            Ritmo ({{ $pacePercent }}% del mes transcurrido)
        </div>
    </div>
    <div id="chartWrapper" style="position:relative;">
        <canvas id="salesChart"></canvas>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
const chartData = @json($chartData);
const pacePercent = {{ $pacePercent }};

const labels = chartData.map(d => d.rep);
const percentData = chartData.map(d => d.quota > 0 ? Math.round(d.sales / d.quota * 1000) / 10 : 0);
const barColors = chartData.map(d => d.sales >= d.pace ? '#3fb950' : '#f85149');
const maxPct = Math.max(110, ...percentData.map(v => v + 10));

// Plugin: single vertical pace line
const paceLinePlugin = {
    id: 'paceLine',
    afterDraw(chart) {
        const { ctx, chartArea: { top, bottom }, scales: { x } } = chart;
        const xPos = x.getPixelForValue(pacePercent);
        ctx.save();
        ctx.beginPath();
        ctx.moveTo(xPos, top);
        ctx.lineTo(xPos, bottom);
        ctx.strokeStyle = '#f0c040';
        ctx.lineWidth = 2;
        ctx.setLineDash([6, 3]);
        ctx.stroke();
        ctx.fillStyle = '#f0c040';
        ctx.font = '11px system-ui, sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('Ritmo', xPos, top - 6);
        ctx.restore();
    }
};

// Plugin: quota labels on the right
const quotaLabelsPlugin = {
    id: 'quotaLabels',
    afterDraw(chart) {
        const { ctx, chartArea: { right }, scales: { y } } = chart;
        ctx.save();
        ctx.fillStyle = '#8b949e';
        ctx.font = '12px system-ui, sans-serif';
        ctx.textAlign = 'left';
        chartData.forEach((d, i) => {
            const yPos = y.getPixelForValue(i);
            const label = d.quota > 0 ? '$' + (d.quota / 1000).toFixed(0) + 'k objetivo' : 'sin cuota';
            ctx.fillText(label, right + 8, yPos + 4);
        });
        ctx.restore();
    }
};

// Plugin: sales value label beside each bar
const barValuePlugin = {
    id: 'barValues',
    afterDatasetsDraw(chart) {
        const { ctx, scales: { x, y } } = chart;
        ctx.save();
        ctx.font = '11px system-ui, sans-serif';
        chartData.forEach((d, i) => {
            if (d.sales === 0) return;
            const val = percentData[i];
            const xPos = x.getPixelForValue(val);
            const x0   = x.getPixelForValue(0);
            const yPos = y.getPixelForValue(i);
            const label = '$' + (d.sales / 1000).toFixed(1) + 'k';
            const barWidth = xPos - x0;
            const textW = ctx.measureText(label).width;

            if (barWidth > textW + 20) {
                ctx.fillStyle = 'rgba(13,17,23,0.85)';
                ctx.textAlign = 'right';
                ctx.fillText(label, xPos - 7, yPos + 4);
            } else {
                ctx.fillStyle = '#8b949e';
                ctx.textAlign = 'left';
                ctx.fillText(label, xPos + 7, yPos + 4);
            }
        });
        ctx.restore();
    }
};

const ctx = document.getElementById('salesChart').getContext('2d');

const chart = new Chart(ctx, {
    type: 'bar',
    data: {
        labels,
        datasets: [{
            label: 'Cumplimiento',
            data: percentData,
            backgroundColor: barColors,
            borderRadius: 4,
            borderSkipped: false,
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        layout: { padding: { right: 100, top: 18 } },
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: ctx => {
                        const d = chartData[ctx.dataIndex];
                        const pct = ctx.parsed.x.toFixed(1);
                        const sales = '$' + d.sales.toLocaleString('en-US', {minimumFractionDigits:0, maximumFractionDigits:0});
                        const quota = d.quota > 0 ? '$' + d.quota.toLocaleString('en-US') : 'Sin cuota';
                        return [` ${pct}% del objetivo`, ` Ventas: ${sales}`, ` Objetivo: ${quota}`];
                    }
                },
                backgroundColor: '#21262d',
                borderColor: '#30363d',
                borderWidth: 1,
                titleColor: '#f0f6fc',
                bodyColor: '#8b949e',
            }
        },
        scales: {
            x: {
                min: 0,
                max: maxPct,
                grid: { color: 'rgba(48,54,61,0.5)' },
                ticks: {
                    color: '#8b949e',
                    callback: v => v + '%'
                },
                border: { color: '#30363d' }
            },
            y: {
                grid: { display: false },
                ticks: { color: '#e6edf3', font: { size: 13 } },
                border: { color: '#30363d' }
            }
        }
    },
    plugins: [paceLinePlugin, quotaLabelsPlugin, barValuePlugin]
});

const height = Math.max(300, labels.length * 70 + 40);
document.getElementById('chartWrapper').style.height = height + 'px';
chart.resize();

function refreshData() {
    const btn = document.getElementById('refreshBtn');
    btn.disabled = true;
    btn.textContent = 'Actualizando…';

    fetch('/refresh', { method: 'POST', headers: { 'Content-Type': 'application/json' } })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast('Datos actualizados — ' + data.rows + ' representantes cargados', 'success');
                setTimeout(() => location.reload(), 1200);
            } else {
                showToast('Error: ' + (data.error || 'Error desconocido'), 'error');
            }
        })
        .catch(() => showToast('Error de red', 'error'))
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '&#8635; Actualizar desde NetSuite';
        });
}

function showToast(msg, type) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast show ' + type;
    setTimeout(() => t.className = 'toast', 3000);
}

// Auto-refresh every 20 min during Argentine working hours (Mon–Fri 8–20 h, UTC-3)
function scheduleNextRefresh() {
    const now  = new Date();
    // Shift by -3h so getUTC* methods return BA local time (Argentina has no DST)
    const ba   = new Date(now.getTime() - 3 * 3600 * 1000);
    const dow  = ba.getUTCDay();    // 0 Sun … 6 Sat
    const hour = ba.getUTCHours(); // 0-23

    if (dow >= 1 && dow <= 5 && hour >= 8 && hour < 20) {
        // In working hours: refresh in 20 minutes
        setTimeout(() => { refreshData(); scheduleNextRefresh(); }, 20 * 60 * 1000);
    } else {
        // Outside hours: sleep until next weekday 8am BA
        const next = new Date(ba);
        next.setUTCHours(8, 0, 0, 0);
        if (hour >= 8 || dow === 0 || dow === 6) next.setUTCDate(next.getUTCDate() + 1);
        while (next.getUTCDay() === 0 || next.getUTCDay() === 6) next.setUTCDate(next.getUTCDate() + 1);
        // Convert BA local back to real UTC (+3h)
        const delay = Math.max(60000, new Date(next.getTime() + 3 * 3600 * 1000) - now);
        setTimeout(scheduleNextRefresh, delay);
    }
}
scheduleNextRefresh();
</script>

</body>
</html>
