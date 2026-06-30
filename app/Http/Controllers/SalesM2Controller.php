<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class SalesM2Controller extends Controller
{
    public function index()
    {
        // Get data from the activity_sales_m2 table ordered by date
        $data = DB::table('activity_sales_m2')
            ->orderBy('date')
            ->orderBy('category')
            ->get()
            ->toArray();

        // Group data by category and quarter
        $groupedData = [];
        $allQuarters = [];

        foreach ($data as $row) {
            $category = $row->category;
            $date = $row->date;

            // Extract year and month, then calculate quarter
            $dateObj = \Carbon\Carbon::parse($date);
            $year = $dateObj->year;
            $month = $dateObj->month;
            $quarter = ceil($month / 3);
            $quarterKey = sprintf('%d-Q%d', $year, $quarter);

            if (!isset($groupedData[$category])) {
                $groupedData[$category] = [];
            }
            if (!isset($groupedData[$category][$quarterKey])) {
                $groupedData[$category][$quarterKey] = 0;
            }
            $groupedData[$category][$quarterKey] += (float) $row->m2;
            $allQuarters[$quarterKey] = true;
        }

        // Sort quarters
        $allQuarters = array_keys($allQuarters);
        sort($allQuarters);

        // Get unique categories sorted
        $categories = array_keys($groupedData);
        sort($categories);

        // Prepare chart data
        $chartData = [
            'labels' => $allQuarters,
            'datasets' => [],
        ];

        $colors = [
            'BOPP' => '#FF6384',
            'HOTMELT' => '#36A2EB',
            'PVC' => '#FFCE56',
            'FREEZER' => '#4BC0C0',
        ];

        foreach ($categories as $category) {
            $values = [];
            foreach ($allQuarters as $quarter) {
                $values[] = $groupedData[$category][$quarter] ?? 0;
            }

            $chartData['datasets'][] = [
                'label' => $category,
                'data' => $values,
                'borderColor' => $colors[$category] ?? '#' . substr(md5($category), 0, 6),
                'backgroundColor' => $colors[$category] ?? '#' . substr(md5($category), 0, 6),
                'borderWidth' => 2,
                'fill' => false,
                'tension' => 0.1,
            ];
        }

        // Prepare market share data
        $marketShareData = $this->getMarketShareData();

        // Prepare Castle S.A. amounts data
        $castleAmountsData = $this->getCastleAmountsData();

        return view('sales-m2', [
            'chartData' => json_encode($chartData),
            'categories' => $categories,
            'marketShareData' => json_encode($marketShareData),
            'castleAmountsData' => json_encode($castleAmountsData),
            'castleSummaryByYear' => $this->getCastleSummaryByYear(),
        ]);
    }

    public function refresh(Request $request)
    {
        try {
            $data = $this->fetchFromNetSuite();

            if (empty($data)) {
                return response()->json(['success' => false, 'error' => 'No data from NetSuite']);
            }

            // Clear current data and insert new data
            DB::table('activity_sales_m2')->truncate();

            $inserted = 0;
            foreach ($data as $row) {
                DB::table('activity_sales_m2')->insert([
                    'date' => $row['date'],
                    'category' => $row['category'],
                    'm2' => $row['m2'],
                    'fetched_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $inserted++;
            }

            return response()->json(['success' => true, 'rows' => $inserted]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function fetchFromNetSuite()
    {
        $realm = env('OAUTH_REALM');
        $consumerKey = env('OAUTH_RESTLET_CONSUMER_KEY');
        $consumerSecret = env('OAUTH_RESTLET_CONSUMER_SECRET');
        $token = env('OAUTH_RESTLET_TOKEN');
        $tokenSecret = env('OAUTH_RESTLET_TOKEN_SECRET');
        $scriptId = env('NETSUITE_M2_SCRIPT_ID', '921');

        if (!$realm || !$consumerKey || !$consumerSecret || !$token || !$tokenSecret) {
            throw new \Exception('Missing NetSuite OAuth credentials');
        }

        $url = "https://{$realm}.restlets.api.netsuite.com/app/site/hosting/restlet.nl?script={$scriptId}&deploy=1";

        // Generate OAuth signature
        $oauth = $this->generateOAuthHeader($url, 'GET', $consumerKey, $consumerSecret, $token, $tokenSecret);

        $response = \Http::withHeaders([
            'Authorization' => $oauth,
            'Content-Type' => 'application/json',
        ])->get($url);

        if (!$response->successful()) {
            throw new \Exception('NetSuite request failed: ' . $response->body());
        }

        $results = $response->json();

        // Transform NetSuite data into our format
        $data = [];
        if (isset($results['data']) && is_array($results['data'])) {
            foreach ($results['data'] as $row) {
                $data[] = [
                    'date' => $row['date'] ?? null,
                    'category' => $row['category'] ?? null,
                    'm2' => (float) ($row['m2'] ?? 0),
                ];
            }
        }

        return $data;
    }

    private function generateOAuthHeader($url, $method, $consumerKey, $consumerSecret, $token, $tokenSecret)
    {
        $nonce = base64_encode(random_bytes(16));
        $timestamp = time();
        $signatureMethod = 'HMAC-SHA256';
        $version = '1.0';

        $params = [
            'oauth_consumer_key' => $consumerKey,
            'oauth_token' => $token,
            'oauth_signature_method' => $signatureMethod,
            'oauth_timestamp' => $timestamp,
            'oauth_nonce' => $nonce,
            'oauth_version' => $version,
        ];

        ksort($params);

        $paramString = '';
        foreach ($params as $key => $value) {
            $paramString .= ($paramString ? '&' : '') . rawurlencode($key) . '=' . rawurlencode($value);
        }

        $baseString = $method . '&' . rawurlencode($url) . '&' . rawurlencode($paramString);
        $signingKey = rawurlencode($consumerSecret) . '&' . rawurlencode($tokenSecret);
        $signature = base64_encode(hash_hmac('sha256', $baseString, $signingKey, true));

        $authHeader = 'OAuth ';
        $authHeader .= 'oauth_consumer_key="' . rawurlencode($consumerKey) . '", ';
        $authHeader .= 'oauth_token="' . rawurlencode($token) . '", ';
        $authHeader .= 'oauth_signature_method="' . rawurlencode($signatureMethod) . '", ';
        $authHeader .= 'oauth_timestamp="' . $timestamp . '", ';
        $authHeader .= 'oauth_nonce="' . rawurlencode($nonce) . '", ';
        $authHeader .= 'oauth_version="' . rawurlencode($version) . '", ';
        $authHeader .= 'oauth_signature="' . rawurlencode($signature) . '"';

        return $authHeader;
    }

    private function getMarketShareData()
    {
        $marketSharePercentages = [
            'BOPP' => [2022 => 8.3, 2023 => 4.9, 2024 => 5.1, 2025 => 4.9],
            'PAPEL' => [2022 => 1.0, 2023 => 1.6, 2024 => 0.7, 2025 => 2.1],
            'FREEZER' => [2022 => 52.0, 2023 => 64.7, 2024 => 37.1, 2025 => 57.8],
            'PVC' => [2022 => 3.4, 2023 => 2.3, 2024 => 1.1, 2025 => 0.0],
            'RESUMEN' => [2022 => 6.5, 2023 => 5.0, 2024 => 4.4, 2025 => 4.3],
        ];

        $years = [2022, 2023, 2024, 2025];
        $categories = ['BOPP', 'PAPEL', 'FREEZER', 'PVC'];

        $chartData = [
            'labels' => $years,
            'datasets' => [],
        ];

        $colors = [
            'BOPP' => '#FF6384',
            'PAPEL' => '#36A2EB',
            'FREEZER' => '#4BC0C0',
            'PVC' => '#FFCE56',
        ];

        foreach ($categories as $category) {
            $values = [];
            foreach ($years as $year) {
                $values[] = $marketSharePercentages[$category][$year] ?? 0;
            }

            $chartData['datasets'][] = [
                'label' => $category . ' Market Share (%)',
                'data' => $values,
                'borderColor' => $colors[$category],
                'backgroundColor' => $colors[$category],
                'borderWidth' => 2,
                'fill' => false,
                'tension' => 0.1,
            ];
        }

        // Add summary line
        $summaryValues = [];
        foreach ($years as $year) {
            $summaryValues[] = $marketSharePercentages['RESUMEN'][$year] ?? 0;
        }

        $chartData['datasets'][] = [
            'label' => 'RESUMEN - Cuota de Mercado Total (%)',
            'data' => $summaryValues,
            'borderColor' => '#000000',
            'backgroundColor' => '#000000',
            'borderWidth' => 3,
            'fill' => false,
            'tension' => 0.1,
        ];

        return $chartData;
    }

    private function seedSampleData()
    {
        // Sample data covering the last 4 years (2022-2026)
        $sampleData = [
            // 2022 Data
            ['2022-01', 'BOPP', 350000],
            ['2022-01', 'HOTMELT', 85000],
            ['2022-01', 'FREEZER', 42000],
            ['2022-01', 'PVC', 38000],
            ['2022-02', 'BOPP', 360000],
            ['2022-02', 'HOTMELT', 88000],
            ['2022-02', 'FREEZER', 45000],
            ['2022-02', 'PVC', 40000],
            ['2022-03', 'BOPP', 375000],
            ['2022-03', 'HOTMELT', 90000],
            ['2022-03', 'FREEZER', 48000],
            ['2022-03', 'PVC', 42000],
            ['2022-04', 'BOPP', 385000],
            ['2022-04', 'HOTMELT', 92000],
            ['2022-04', 'FREEZER', 50000],
            ['2022-04', 'PVC', 44000],
            ['2022-05', 'BOPP', 395000],
            ['2022-05', 'HOTMELT', 95000],
            ['2022-05', 'FREEZER', 52000],
            ['2022-05', 'PVC', 46000],
            ['2022-06', 'BOPP', 405000],
            ['2022-06', 'HOTMELT', 98000],
            ['2022-06', 'FREEZER', 55000],
            ['2022-06', 'PVC', 48000],
            ['2022-07', 'BOPP', 415000],
            ['2022-07', 'HOTMELT', 100000],
            ['2022-07', 'FREEZER', 58000],
            ['2022-07', 'PVC', 50000],
            ['2022-08', 'BOPP', 425000],
            ['2022-08', 'HOTMELT', 102000],
            ['2022-08', 'FREEZER', 60000],
            ['2022-08', 'PVC', 52000],
            ['2022-09', 'BOPP', 435000],
            ['2022-09', 'HOTMELT', 105000],
            ['2022-09', 'FREEZER', 62000],
            ['2022-09', 'PVC', 54000],
            ['2022-10', 'BOPP', 445000],
            ['2022-10', 'HOTMELT', 107000],
            ['2022-10', 'FREEZER', 65000],
            ['2022-10', 'PVC', 56000],
            ['2022-11', 'BOPP', 455000],
            ['2022-11', 'HOTMELT', 110000],
            ['2022-11', 'FREEZER', 68000],
            ['2022-11', 'PVC', 58000],
            ['2022-12', 'BOPP', 465000],
            ['2022-12', 'HOTMELT', 112000],
            ['2022-12', 'FREEZER', 70000],
            ['2022-12', 'PVC', 60000],
            // 2023 Data
            ['2023-01', 'BOPP', 475000],
            ['2023-01', 'HOTMELT', 115000],
            ['2023-01', 'FREEZER', 72000],
            ['2023-01', 'PVC', 62000],
            ['2023-02', 'BOPP', 485000],
            ['2023-02', 'HOTMELT', 118000],
            ['2023-02', 'FREEZER', 75000],
            ['2023-02', 'PVC', 64000],
            ['2023-03', 'BOPP', 495000],
            ['2023-03', 'HOTMELT', 120000],
            ['2023-03', 'FREEZER', 78000],
            ['2023-03', 'PVC', 66000],
            ['2023-04', 'BOPP', 505000],
            ['2023-04', 'HOTMELT', 122000],
            ['2023-04', 'FREEZER', 80000],
            ['2023-04', 'PVC', 68000],
            ['2023-05', 'BOPP', 515000],
            ['2023-05', 'HOTMELT', 125000],
            ['2023-05', 'FREEZER', 82000],
            ['2023-05', 'PVC', 70000],
            ['2023-06', 'BOPP', 525000],
            ['2023-06', 'HOTMELT', 127000],
            ['2023-06', 'FREEZER', 85000],
            ['2023-06', 'PVC', 72000],
            ['2023-07', 'BOPP', 535000],
            ['2023-07', 'HOTMELT', 130000],
            ['2023-07', 'FREEZER', 88000],
            ['2023-07', 'PVC', 74000],
            ['2023-08', 'BOPP', 545000],
            ['2023-08', 'HOTMELT', 132000],
            ['2023-08', 'FREEZER', 90000],
            ['2023-08', 'PVC', 76000],
            ['2023-09', 'BOPP', 555000],
            ['2023-09', 'HOTMELT', 135000],
            ['2023-09', 'FREEZER', 92000],
            ['2023-09', 'PVC', 78000],
            ['2023-10', 'BOPP', 565000],
            ['2023-10', 'HOTMELT', 137000],
            ['2023-10', 'FREEZER', 95000],
            ['2023-10', 'PVC', 80000],
            ['2023-11', 'BOPP', 575000],
            ['2023-11', 'HOTMELT', 140000],
            ['2023-11', 'FREEZER', 98000],
            ['2023-11', 'PVC', 82000],
            ['2023-12', 'BOPP', 585000],
            ['2023-12', 'HOTMELT', 142000],
            ['2023-12', 'FREEZER', 100000],
            ['2023-12', 'PVC', 84000],
            // 2024 Data
            ['2024-01', 'BOPP', 595000],
            ['2024-01', 'HOTMELT', 145000],
            ['2024-01', 'FREEZER', 102000],
            ['2024-01', 'PVC', 86000],
            ['2024-02', 'BOPP', 605000],
            ['2024-02', 'HOTMELT', 147000],
            ['2024-02', 'FREEZER', 105000],
            ['2024-02', 'PVC', 88000],
            ['2024-03', 'BOPP', 615000],
            ['2024-03', 'HOTMELT', 150000],
            ['2024-03', 'FREEZER', 107000],
            ['2024-03', 'PVC', 90000],
            ['2024-04', 'BOPP', 625000],
            ['2024-04', 'HOTMELT', 152000],
            ['2024-04', 'FREEZER', 110000],
            ['2024-04', 'PVC', 92000],
            ['2024-05', 'BOPP', 635000],
            ['2024-05', 'HOTMELT', 155000],
            ['2024-05', 'FREEZER', 112000],
            ['2024-05', 'PVC', 94000],
            ['2024-06', 'BOPP', 645000],
            ['2024-06', 'HOTMELT', 157000],
            ['2024-06', 'FREEZER', 115000],
            ['2024-06', 'PVC', 96000],
            ['2024-07', 'BOPP', 655000],
            ['2024-07', 'HOTMELT', 160000],
            ['2024-07', 'FREEZER', 118000],
            ['2024-07', 'PVC', 98000],
            ['2024-08', 'BOPP', 665000],
            ['2024-08', 'HOTMELT', 162000],
            ['2024-08', 'FREEZER', 120000],
            ['2024-08', 'PVC', 100000],
            ['2024-09', 'BOPP', 675000],
            ['2024-09', 'HOTMELT', 165000],
            ['2024-09', 'FREEZER', 122000],
            ['2024-09', 'PVC', 102000],
            ['2024-10', 'BOPP', 685000],
            ['2024-10', 'HOTMELT', 167000],
            ['2024-10', 'FREEZER', 125000],
            ['2024-10', 'PVC', 104000],
            ['2024-11', 'BOPP', 695000],
            ['2024-11', 'HOTMELT', 170000],
            ['2024-11', 'FREEZER', 128000],
            ['2024-11', 'PVC', 106000],
            ['2024-12', 'BOPP', 705000],
            ['2024-12', 'HOTMELT', 172000],
            ['2024-12', 'FREEZER', 130000],
            ['2024-12', 'PVC', 108000],
            // 2025 Data
            ['2025-01', 'BOPP', 715000],
            ['2025-01', 'HOTMELT', 175000],
            ['2025-01', 'FREEZER', 132000],
            ['2025-01', 'PVC', 110000],
            ['2025-02', 'BOPP', 725000],
            ['2025-02', 'HOTMELT', 177000],
            ['2025-02', 'FREEZER', 135000],
            ['2025-02', 'PVC', 112000],
            ['2025-03', 'BOPP', 735000],
            ['2025-03', 'HOTMELT', 180000],
            ['2025-03', 'FREEZER', 138000],
            ['2025-03', 'PVC', 114000],
            ['2025-04', 'BOPP', 519694.08],
            ['2025-04', 'HOTMELT', 186944.64],
            ['2025-04', 'FREEZER', 4800],
            ['2025-04', 'PVC', 3373.92],
            ['2025-05', 'PVC', 11521.224],
            ['2025-05', 'BOPP', 493937.28],
            ['2025-05', 'HOTMELT', 82874.88],
            ['2025-05', 'FREEZER', 18816],
            ['2025-06', 'BOPP', 464424],
            ['2025-06', 'HOTMELT', 66618.24],
            ['2025-06', 'PVC', 4573.008],
            ['2025-06', 'FREEZER', 8424],
            ['2025-07', 'HOTMELT', 102609.12],
            ['2025-07', 'PVC', 3089.04],
            ['2025-07', 'FREEZER', 11664],
            ['2025-07', 'BOPP', 350016],
            ['2025-08', 'BOPP', 433737.84],
            ['2025-08', 'FREEZER', 33264],
            ['2025-08', 'HOTMELT', 119827.68],
            ['2025-08', 'PVC', 5223.504],
            ['2025-09', 'PVC', 2737.152],
            ['2025-09', 'HOTMELT', 104788.32],
            ['2025-09', 'FREEZER', 29376],
            ['2025-09', 'BOPP', 419424.96],
            ['2025-10', 'FREEZER', 36672],
            ['2025-10', 'BOPP', 576144],
            ['2025-10', 'HOTMELT', 174700.64],
            ['2025-10', 'PVC', 4618.112],
            ['2025-11', 'PVC', 14357.376],
            ['2025-11', 'HOTMELT', 154532.16],
            ['2025-11', 'BOPP', 673084.8],
            ['2025-11', 'FREEZER', 19440],
            ['2025-12', 'BOPP', 422277.6],
            ['2025-12', 'PVC', 3640.032],
            ['2025-12', 'FREEZER', 13584],
            ['2025-12', 'HOTMELT', 89919.36],
            // 2026 Data
            ['2026-01', 'BOPP', 853669.44],
            ['2026-01', 'HOTMELT', 92015.04],
            ['2026-01', 'FREEZER', 45888],
            ['2026-01', 'PVC', 9345.6],
            ['2026-02', 'HOTMELT', 79238.4],
            ['2026-02', 'BOPP', 597280.32],
            ['2026-02', 'PVC', 3798.432],
            ['2026-02', 'FREEZER', 23808],
            ['2026-03', 'FREEZER', 36384],
            ['2026-03', 'BOPP', 708498.24],
            ['2026-03', 'HOTMELT', 203831.04],
            ['2026-03', 'PVC', 6500.736],
            ['2026-04', 'BOPP', 519694.08],
            ['2026-04', 'HOTMELT', 186944.64],
            ['2026-04', 'FREEZER', 4800],
            ['2026-04', 'PVC', 3373.92],
            ['2026-05', 'PVC', 11521.224],
            ['2026-05', 'BOPP', 493937.28],
            ['2026-05', 'HOTMELT', 82874.88],
            ['2026-05', 'FREEZER', 18816],
            ['2026-06', 'BOPP', 53136],
            ['2026-06', 'HOTMELT', 1036.8],
        ];

        foreach ($sampleData as [$dateStr, $category, $m2]) {
            $date = \Carbon\Carbon::createFromFormat('Y-m', $dateStr)->endOfMonth()->toDateString();

            DB::table('activity_sales_m2')->updateOrInsert(
                [
                    'date' => $date,
                    'category' => $category,
                ],
                [
                    'm2' => $m2,
                    'fetched_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    private function getCastleAmountsData()
    {
        $castleAmounts = [
            'BOPP' => [2022 => 1335132, 2023 => 662505, 2024 => 705136, 2025 => 757682],
            'PAPEL' => [2022 => 86067, 2023 => 109439, 2024 => 48404, 2025 => 151604],
            'FREEZER' => [2022 => 301343, 2023 => 495764, 2024 => 229800, 2025 => 244911],
            'PVC' => [2022 => 121648, 2023 => 169508, 2024 => 21559, 2025 => 0],
        ];

        $years = [2022, 2023, 2024, 2025];
        $categories = ['BOPP', 'PAPEL', 'FREEZER', 'PVC'];

        $chartData = [
            'labels' => $years,
            'datasets' => [],
        ];

        $colors = [
            'BOPP' => '#FF6384',
            'PAPEL' => '#36A2EB',
            'FREEZER' => '#4BC0C0',
            'PVC' => '#FFCE56',
        ];

        foreach ($categories as $category) {
            $values = [];
            foreach ($years as $year) {
                $values[] = $castleAmounts[$category][$year] ?? 0;
            }

            $chartData['datasets'][] = [
                'label' => $category . ' - Castle S.A. (U$D)',
                'data' => $values,
                'borderColor' => $colors[$category],
                'backgroundColor' => $colors[$category],
                'borderWidth' => 2,
                'fill' => false,
                'tension' => 0.1,
            ];
        }

        return $chartData;
    }

    private function getCastleSummaryByYear()
    {
        $castleByYear = [
            2020 => 983054,
            2021 => 1526222,
            2022 => 1844190,
            2023 => 1437216,
            2024 => 1004899,
            2025 => 1154197,
        ];

        $byCategory = [
            'BOPP' => [
                2020 => 742588, 2021 => 1139274, 2022 => 1335132, 2023 => 662505, 2024 => 705136, 2025 => 757682
            ],
            'PAPEL' => [
                2020 => 69982, 2021 => 100479, 2022 => 86067, 2023 => 109439, 2024 => 48404, 2025 => 151604
            ],
            'FREEZER' => [
                2020 => 121636, 2021 => 226122, 2022 => 301343, 2023 => 495764, 2024 => 229800, 2025 => 244911
            ],
            'PVC' => [
                2020 => 48848, 2021 => 60347, 2022 => 121648, 2023 => 169508, 2024 => 21559, 2025 => 0
            ],
        ];

        return [
            'byYear' => $castleByYear,
            'byCategory' => $byCategory,
        ];
    }
}
