<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GEP - Ventas M2</title>
    @vite('resources/css/app.css')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-50">
    <div class="min-h-screen flex flex-col">
        <!-- Navigation -->
        <nav class="bg-white shadow-md">
            <div class="max-w-7xl mx-auto px-4 py-4 flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">GEP - Ventas M2</h1>
                    <p class="text-gray-600 text-sm">Gráfico de ventas por categoría (metros cuadrados)</p>
                </div>
                <button onclick="refreshM2Data()" class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded">
                    ↺ Actualizar desde NetSuite
                </button>
            </div>
        </nav>

        <!-- Main Content -->
        <main class="flex-1 max-w-7xl mx-auto w-full p-4">
            <!-- First Chart: Ventas M2 -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-8">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Ventas por Categoría (M2)</h2>
                <!-- Chart -->
                <div class="mb-8">
                    <canvas id="salesChart" style="max-height: 400px;"></canvas>
                </div>
            </div>

            <!-- Second Chart: Market Share -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-8">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Cuota de Mercado de Castle S.A. (%) - 2022 a 2025</h2>
                <p class="text-sm text-gray-600 mb-4">Todos los datos están basados en CIF Argentina y los montos están en U$D</p>
                <!-- Chart -->
                <div class="mb-8">
                    <canvas id="marketShareChart" style="max-height: 400px;"></canvas>
                </div>
            </div>

            <!-- Third Chart: Castle S.A. Amounts -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Montos de Castle S.A. por Categoría (U$D) - 2022 a 2025</h2>
                <!-- Chart -->
                <div class="mb-8">
                    <canvas id="castleAmountsChart" style="max-height: 400px;"></canvas>
                </div>
            </div>

            <!-- Market Share Summary Table -->
            <div class="bg-white rounded-lg shadow-md p-6 mt-8 mb-8">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Resumen de Cuota de Mercado 2025 vs 2022</h2>
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse border border-gray-300">
                        <thead>
                            <tr class="bg-gray-100">
                                <th class="border border-gray-300 px-4 py-2 text-left">Categoría</th>
                                <th class="border border-gray-300 px-4 py-2 text-right">2025</th>
                                <th class="border border-gray-300 px-4 py-2 text-right">2022</th>
                                <th class="border border-gray-300 px-4 py-2 text-right">Cambio</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="hover:bg-gray-50">
                                <td class="border border-gray-300 px-4 py-2">BOPP</td>
                                <td class="border border-gray-300 px-4 py-2 text-right">4.9%</td>
                                <td class="border border-gray-300 px-4 py-2 text-right">8.3%</td>
                                <td class="border border-gray-300 px-4 py-2 text-right text-red-600">-3.4%</td>
                            </tr>
                            <tr class="hover:bg-gray-50">
                                <td class="border border-gray-300 px-4 py-2">PAPEL</td>
                                <td class="border border-gray-300 px-4 py-2 text-right">2.1%</td>
                                <td class="border border-gray-300 px-4 py-2 text-right">1.0%</td>
                                <td class="border border-gray-300 px-4 py-2 text-right text-green-600">+1.1%</td>
                            </tr>
                            <tr class="hover:bg-gray-50">
                                <td class="border border-gray-300 px-4 py-2">FREEZER</td>
                                <td class="border border-gray-300 px-4 py-2 text-right">57.8%</td>
                                <td class="border border-gray-300 px-4 py-2 text-right">52.0%</td>
                                <td class="border border-gray-300 px-4 py-2 text-right text-green-600">+5.8%</td>
                            </tr>
                            <tr class="hover:bg-gray-50">
                                <td class="border border-gray-300 px-4 py-2">PVC</td>
                                <td class="border border-gray-300 px-4 py-2 text-right">0.0%</td>
                                <td class="border border-gray-300 px-4 py-2 text-right">3.4%</td>
                                <td class="border border-gray-300 px-4 py-2 text-right text-red-600">-3.4%</td>
                            </tr>
                            <tr class="bg-gray-100 font-semibold hover:bg-gray-50">
                                <td class="border border-gray-300 px-4 py-2">RESUMEN</td>
                                <td class="border border-gray-300 px-4 py-2 text-right">4.3%</td>
                                <td class="border border-gray-300 px-4 py-2 text-right">6.5%</td>
                                <td class="border border-gray-300 px-4 py-2 text-right text-red-600">-2.2%</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Castle S.A. Amounts by Category -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Montos de Castle S.A. por Categoría (U$D)</h2>
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse border border-gray-300">
                        <thead>
                            <tr class="bg-gray-100">
                                <th class="border border-gray-300 px-4 py-2 text-left">Categoría</th>
                                <th class="border border-gray-300 px-4 py-2 text-right">2022</th>
                                <th class="border border-gray-300 px-4 py-2 text-right">2023</th>
                                <th class="border border-gray-300 px-4 py-2 text-right">2024</th>
                                <th class="border border-gray-300 px-4 py-2 text-right">2025</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="hover:bg-gray-50">
                                <td class="border border-gray-300 px-4 py-2 font-semibold">BOPP</td>
                                <td class="border border-gray-300 px-4 py-2 text-right">$1,335,132</td>
                                <td class="border border-gray-300 px-4 py-2 text-right">$662,505</td>
                                <td class="border border-gray-300 px-4 py-2 text-right">$705,136</td>
                                <td class="border border-gray-300 px-4 py-2 text-right">$757,682</td>
                            </tr>
                            <tr class="hover:bg-gray-50">
                                <td class="border border-gray-300 px-4 py-2 font-semibold">PAPEL</td>
                                <td class="border border-gray-300 px-4 py-2 text-right">$86,067</td>
                                <td class="border border-gray-300 px-4 py-2 text-right">$109,439</td>
                                <td class="border border-gray-300 px-4 py-2 text-right">$48,404</td>
                                <td class="border border-gray-300 px-4 py-2 text-right">$151,604</td>
                            </tr>
                            <tr class="hover:bg-gray-50">
                                <td class="border border-gray-300 px-4 py-2 font-semibold">FREEZER</td>
                                <td class="border border-gray-300 px-4 py-2 text-right">$301,343</td>
                                <td class="border border-gray-300 px-4 py-2 text-right">$495,764</td>
                                <td class="border border-gray-300 px-4 py-2 text-right">$229,800</td>
                                <td class="border border-gray-300 px-4 py-2 text-right">$244,911</td>
                            </tr>
                            <tr class="hover:bg-gray-50">
                                <td class="border border-gray-300 px-4 py-2 font-semibold">PVC</td>
                                <td class="border border-gray-300 px-4 py-2 text-right">$121,648</td>
                                <td class="border border-gray-300 px-4 py-2 text-right">$169,508</td>
                                <td class="border border-gray-300 px-4 py-2 text-right">$21,559</td>
                                <td class="border border-gray-300 px-4 py-2 text-right">$0</td>
                            </tr>
                            <tr class="bg-gray-100 font-semibold hover:bg-gray-50">
                                <td class="border border-gray-300 px-4 py-2">RESUMEN</td>
                                <td class="border border-gray-300 px-4 py-2 text-right">$1,844,190</td>
                                <td class="border border-gray-300 px-4 py-2 text-right">$1,437,216</td>
                                <td class="border border-gray-300 px-4 py-2 text-right">$1,004,899</td>
                                <td class="border border-gray-300 px-4 py-2 text-right">$1,154,197</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="mt-8 flex gap-4 pb-8">
                <a href="/" class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded">
                    Volver al Dashboard
                </a>
                <button onclick="downloadChart()" class="bg-green-500 hover:bg-green-600 text-white font-bold py-2 px-4 rounded">
                    Descargar Gráfico
                </button>
            </div>
        </main>
    </div>

    <script>
        function refreshM2Data() {
            const btn = event.target;
            btn.disabled = true;
            btn.textContent = 'Actualizando…';
            fetch('/refresh-ventas-m2', { method: 'POST', headers: { 'Content-Type': 'application/json' } })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        alert('Datos actualizados: ' + data.rows + ' registros');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        alert('Error: ' + (data.error || 'Error desconocido'));
                    }
                })
                .catch(() => alert('Error de red'))
                .finally(() => { btn.disabled = false; btn.textContent = '↺ Actualizar desde NetSuite'; });
        }

        // First Chart: Ventas M2
        const chartData = {!! $chartData !!};

        const ctx = document.getElementById('salesChart').getContext('2d');
        const chart = new Chart(ctx, {
            type: 'line',
            data: chartData,
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            boxWidth: 12,
                            padding: 15,
                        }
                    }
                },
                scales: {
                    x: {
                        display: true,
                        title: {
                            display: true,
                            text: 'Fecha'
                        }
                    },
                    y: {
                        display: true,
                        title: {
                            display: true,
                            text: 'Metros Cuadrados (M2)'
                        },
                        beginAtZero: true
                    }
                }
            }
        });

        // Second Chart: Market Share
        const marketShareData = {!! $marketShareData !!};

        const ctx2 = document.getElementById('marketShareChart').getContext('2d');
        const chart2 = new Chart(ctx2, {
            type: 'line',
            data: marketShareData,
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            boxWidth: 12,
                            padding: 15,
                        }
                    }
                },
                scales: {
                    x: {
                        display: true,
                        title: {
                            display: true,
                            text: 'Año'
                        }
                    },
                    y: {
                        display: true,
                        title: {
                            display: true,
                            text: 'Cuota de Mercado (%)'
                        },
                        beginAtZero: true,
                        max: 100
                    }
                }
            }
        });

        // Third Chart: Castle S.A. Amounts
        const castleAmountsData = {!! $castleAmountsData !!};

        const ctx3 = document.getElementById('castleAmountsChart').getContext('2d');
        const chart3 = new Chart(ctx3, {
            type: 'line',
            data: castleAmountsData,
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            boxWidth: 12,
                            padding: 15,
                        }
                    }
                },
                scales: {
                    x: {
                        display: true,
                        title: {
                            display: true,
                            text: 'Año'
                        }
                    },
                    y: {
                        display: true,
                        title: {
                            display: true,
                            text: 'Monto (U$D)'
                        },
                        beginAtZero: true
                    }
                }
            }
        });

        function downloadChart() {
            const link = document.createElement('a');
            link.href = chart.toBase64Image();
            link.download = 'ventas-m2-grafico.png';
            link.click();
        }
    </script>
</body>
</html>
