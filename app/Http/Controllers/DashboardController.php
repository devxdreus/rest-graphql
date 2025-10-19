<?php

namespace App\Http\Controllers;

use App\Models\RequestLog;
use App\Models\PerformanceMetric;
use App\Services\ApiGatewayService;
use App\Services\SystemMetricsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    protected $apiGatewayService;
    protected $systemMetricsService;
    
    public function __construct(ApiGatewayService $apiGatewayService, SystemMetricsService $systemMetricsService)
    {
        $this->apiGatewayService = $apiGatewayService;
        $this->systemMetricsService = $systemMetricsService;
    }
    
    public function index()
    {
        // Mengambil statistik sistem
        $metrics = $this->getSystemMetrics();
        
        // Mengambil data untuk grafik
        $chart_data = $this->getChartData();
        
        // Mengambil riwayat pengujian terbaru terpisah per API type
        $recent_rest_tests = RequestLog::selectRaw('
            query_id,
            MAX(created_at) as latest_test_time,
            AVG(rest_response_time_ms) as avg_rest_time,
            COUNT(*) as test_count,
            MAX(cache_status) as cache_status,
            "rest" as api_type
        ')
        ->where('rest_response_time_ms', '>', 0)
        ->groupBy('query_id', DB::raw('DATE(created_at)'))
        ->orderBy('latest_test_time', 'desc')
        ->take(5)
        ->get();

        $recent_graphql_tests = RequestLog::selectRaw('
            query_id,
            MAX(created_at) as latest_test_time,
            AVG(graphql_response_time_ms) as avg_graphql_time,
            COUNT(*) as test_count,
            MAX(cache_status) as cache_status,
            "graphql" as api_type
        ')
        ->where('graphql_response_time_ms', '>', 0)
        ->groupBy('query_id', DB::raw('DATE(created_at)'))
        ->orderBy('latest_test_time', 'desc')
        ->take(5)
        ->get();

        $recent_integrated_tests = RequestLog::selectRaw('
            query_id,
            MAX(created_at) as latest_test_time,
            AVG(rest_response_time_ms) as avg_rest_time,
            AVG(graphql_response_time_ms) as avg_graphql_time,
            COUNT(*) as test_count,
            MAX(winner_api) as latest_winner,
            MAX(cache_status) as cache_status,
            "integrated" as api_type
        ')
        ->where('response_body', 'LIKE', '%"integrated_api":true%')
        ->groupBy('query_id', DB::raw('DATE(created_at)'))
        ->orderBy('latest_test_time', 'desc')
        ->take(5)
        ->get();

        // Mengambil riwayat pengujian terbaru - GRUPKAN berdasarkan query_id dan created_at (untuk tab ALL)
        $recent_tests = RequestLog::selectRaw('
            query_id,
            MAX(created_at) as latest_test_time,
            AVG(rest_response_time_ms) as avg_rest_time,
            AVG(graphql_response_time_ms) as avg_graphql_time,
            COUNT(*) as test_count,
            MAX(winner_api) as latest_winner,
            MAX(cache_status) as cache_status,
            "all" as api_type
        ')
        ->groupBy('query_id', DB::raw('DATE(created_at)'))
        ->orderBy('latest_test_time', 'desc')
        ->take(10)
        ->get();
            
        $availableQueries = $this->getAvailableQueries();
        $queryDetails = [];
        foreach ($availableQueries as $id => $description) {
            $endpoints = $this->apiGatewayService->getEndpointsForQuery($id, null);
            $queryDetails[$id] = [
                'description' => $description,
                'rest' => $endpoints['rest'],
                'graphql' => $endpoints['graphql']['query'],
            ];
        }
        
        // Ambil query yang memiliki data di database untuk dropdown perbandingan
        $availableComparisonQueries = $this->getQueriesWithData();
            
        return view('dashboard', compact('metrics', 'chart_data', 'recent_tests', 'recent_rest_tests', 'recent_graphql_tests', 'recent_integrated_tests', 'availableQueries', 'queryDetails', 'availableComparisonQueries'));
    }
    
    public function startTest(Request $request)
    {
        try {
            $request->validate([
                'query_id' => 'required|string',
                'repository' => 'nullable|string',
                'cache' => 'required|boolean'
            ]);
            
            $queryId = $request->input('query_id');
            $repository = $request->input('repository');
            $useCache = $request->boolean('cache');
            
            Log::info('Starting test', [
                'query_id' => $queryId,
                'repository' => $repository,
                'use_cache' => $useCache
            ]);
            
            $result = $this->apiGatewayService->executeTest(
                $queryId,
                $repository,
                $useCache
            );
            
            if (isset($result['error']) && $result['error']) {
                Log::error('Error in test execution: ' . $result['message']);
                return response()->json($result, 500);
            }
            
            // Pecah response_data menjadi dua key terpisah agar sesuai kebutuhan frontend
            $response_data_rest = $result['response_data']['rest'] ?? [];
            $response_data_graphql = $result['response_data']['graphql'] ?? [];
            $result['response_data_rest'] = $response_data_rest;
            $result['response_data_graphql'] = $response_data_graphql;
            unset($result['response_data']);
            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('Exception in startTest: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => true,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Run performance test for dashboard - similar to PerformanceMetricController
     */
    public function runPerformanceTest(Request $request)
    {
        try {
            $request->validate([
                'query_id' => 'required|string',
                'api_type' => 'required|in:rest,graphql,integrated',
                'request_count' => 'required|integer|min:1|max:1000'
            ]);
            
            $queryId = $request->input('query_id');
            $apiType = $request->input('api_type');
            $requestCount = $request->input('request_count');
            
            // Start measuring CPU and memory
            $startCpuUsage = $this->systemMetricsService->getCpuUsage();
            $startMemoryUsage = $this->systemMetricsService->getMemoryUsage();
            
            // Run the test
            $startTime = microtime(true);
            $responseTimes = [];
            
            for ($i = 0; $i < $requestCount; $i++) {
                $requestStartTime = microtime(true);
                
                // Execute API request
                $response = $this->executeApiRequest($queryId, $apiType);
                
                $requestEndTime = microtime(true);
                $responseTimes[] = ($requestEndTime - $requestStartTime) * 1000; // Convert to ms
            }
            
            $endTime = microtime(true);
            
            // End CPU and memory measurement
            $endCpuUsage = $this->systemMetricsService->getCpuUsage();
            $endMemoryUsage = $this->systemMetricsService->getMemoryUsage();
            
            // Calculate average response time
            $avgResponseTime = array_sum($responseTimes) / count($responseTimes);
            
            // Calculate CPU and memory usage
            $cpuUsage = ($endCpuUsage + $startCpuUsage) / 2;
            $memoryUsage = ($endMemoryUsage + $startMemoryUsage) / 2;
            
            // Save the measurement result
            $metric = PerformanceMetric::create([
                'query_id' => $queryId,
                'api_type' => $apiType,
                'cpu_usage_percent' => $cpuUsage,
                'memory_usage_percent' => $memoryUsage,
                'request_count' => $requestCount,
                'avg_response_time_ms' => $avgResponseTime
            ]);
            
            $metricData = $metric->toArray();
            
            return response()->json([
                'success' => true,
                'data' => array_merge($metricData, [
                    'details' => [
                        'total_time' => ($endTime - $startTime) * 1000,
                        'response_times' => $responseTimes
                    ]
                ])
            ]);
        } catch (\Exception $e) {
            Log::error('Exception in runPerformanceTest: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Execute API request based on type
     */
    private function executeApiRequest($queryId, $apiType)
    {
        switch ($apiType) {
            case 'rest':
                $result = $this->apiGatewayService->executeRestApi($queryId);
                
                // Log the REST test result to RequestLog for history tracking
                $this->logSingleApiResult($queryId, 'rest', $result);
                
                return [
                    'response_time_ms' => $result['response_time_ms'],
                    'succeeded' => $result['succeeded']
                ];
            case 'graphql':
                $result = $this->apiGatewayService->executeGraphqlApi($queryId);
                
                // Log the GraphQL test result to RequestLog for history tracking
                $this->logSingleApiResult($queryId, 'graphql', $result);
                
                return [
                    'response_time_ms' => $result['response_time_ms'],
                    'succeeded' => $result['succeeded']
                ];
            case 'integrated':
                // Use integrated API with intelligent caching
                // Check if we should use Promise::any() for fastest response (50% chance for testing)
                $usePromiseAny = rand(0, 1) === 1;
                $result = $this->apiGatewayService->executeIntegratedApi($queryId, null, $usePromiseAny);
                
                // Log this integrated test result to RequestLog for history tracking
                $this->logIntegratedApiResult($result);
                
                return [
                    'response_time_ms' => $result['total_response_time_ms'] ?? 
                                         max($result['rest_response_time_ms'] ?? 0, $result['graphql_response_time_ms'] ?? 0),
                    'succeeded' => $result['rest_succeeded'] || $result['graphql_succeeded'],
                    'cache_used' => $result['cache_used'] ?? false,
                    'selected_api' => $result['selected_api'] ?? 'both',
                    'execution_mode' => $result['execution_mode'] ?? 'unknown',
                    'used_promise_any' => $usePromiseAny
                ];
            default:
                throw new \Exception("Invalid API type: $apiType");
        }
    }
    
    public function showLogs()
    {
        try {
            $logs = RequestLog::orderBy('created_at', 'desc')->paginate(20);
            return view('logs', compact('logs'));
        } catch (\Exception $e) {
            Log::error('Exception in showLogs: ' . $e->getMessage());
            return back()->with('error', 'Terjadi kesalahan saat mengambil data log.');
        }
    }
    
    public function showApiEndpoints()
    {
        try {
            $availableQueries = $this->getAvailableQueries();
            $queryDetails = [];
            
            foreach ($availableQueries as $id => $description) {
                $endpoints = $this->apiGatewayService->getEndpointsForQuery($id, null);
                $queryDetails[$id] = [
                    'description' => $description,
                    'rest' => $endpoints['rest'],
                    'graphql' => $endpoints['graphql']['query'],
                ];
            }
            
            return view('api-endpoints', compact('queryDetails'));
        } catch (\Exception $e) {
            Log::error('Exception in showApiEndpoints: ' . $e->getMessage());
            return back()->with('error', 'Terjadi kesalahan saat mengambil data endpoint API.');
        }
    }
    
    protected function getAvailableQueries()
    {
        return [
            'Q1' => 'Sederhana, Over-fetching: Mengambil nama dari 100 project teratas berdasarkan jumlah stars',
            'Q2' => 'Kompleks, Under-fetching: Untuk setiap project, mengambil jumlah total pull request dan isi dari 1.000 pull request terbaru',
            'Q3' => 'Sederhana, Under-fetching: Untuk setiap pull request, mengambil isi dari komentar',
            'Q4' => 'Sederhana, Over-fetching: Mengambil nama dan URL dari 5 project teratas berdasarkan jumlah stars',
            'Q5' => 'Sederhana, Over-fetching: Untuk tujuh project, mengambil jumlah commit, branch, bug, release, dan kontributor',
            'Q6' => 'Kompleks, Under-fetching: Untuk setiap project, mengambil judul dan isi dari bug yang sudah ditutup',
            'Q7' => 'Sederhana, Under-fetching: Untuk setiap bug yang sudah ditutup, mengambil isi dari komentar',
            'Q8' => 'Sederhana, Over-fetching: Mengambil nama dan URL dari project Java yang dibuat sebelum Januari 2012 dengan 10+ stars dan 1+ commit',
            'Q9' => 'Sederhana, Over-fetching: Mengambil jumlah stars dari project tertentu',
            'Q10' => 'Sederhana, Over-fetching: Mengambil nama repository dengan setidaknya 1.000 stars',
            'Q11' => 'Kompleks, Under-fetching: Mengambil jumlah commit dalam sebuah repository',
            'Q12' => 'Sederhana, Over-fetching: Untuk delapan project, mengambil jumlah release, stars, dan bahasa pemrograman yang digunakan',
            'Q13' => 'Kompleks, Under-fetching: Mengambil judul, isi, tanggal, dan nama project dari open issue yang ditandai dengan tag "bug"',
            'Q14' => 'Kompleks, Under-fetching: Untuk setiap issue, mengambil isi dari komentar'
        ];
    }

    private function getSystemMetrics()
    {
        try {
            // Mengambil penggunaan CPU dan Memory
            if (PHP_OS_FAMILY === 'Windows') {
                $cpu_usage = $this->getWindowsCpuUsage();
                $memory_usage = $this->getWindowsMemoryUsage();
                $disk_usage = $this->getWindowsDiskUsage();
            } else {
                $cpu_usage = $this->getLinuxCpuUsage();
                $memory_usage = $this->getLinuxMemoryUsage();
                $disk_usage = $this->getLinuxDiskUsage();
            }

            // Menghitung statistik dari database
            $total_tests = RequestLog::count();
            $cache_hits = RequestLog::where('cache_status', 'HIT')->count();
            $cache_hit_rate = $total_tests > 0 ? round(($cache_hits / $total_tests) * 100) : 0;
            
            $rest_wins = RequestLog::where('winner_api', 'rest')->count();
            $graphql_wins = RequestLog::where('winner_api', 'graphql')->count();
            
            $rest_win_rate = $total_tests > 0 ? round(($rest_wins / $total_tests) * 100) : 0;
            $graphql_win_rate = $total_tests > 0 ? round(($graphql_wins / $total_tests) * 100) : 0;

            return [
                'cpu_usage' => round($cpu_usage),
                'memory_usage' => round($memory_usage),
                'disk_usage' => round($disk_usage),
                'total_tests' => $total_tests,
                'cache_hit_rate' => $cache_hit_rate,
                'rest_wins' => $rest_win_rate,
                'graphql_wins' => $graphql_win_rate
            ];
        } catch (\Exception $e) {
            \Log::error('Error getting system metrics: ' . $e->getMessage());
            return [
                'cpu_usage' => 0,
                'memory_usage' => 0,
                'disk_usage' => 0,
                'total_tests' => 0,
                'cache_hit_rate' => 0,
                'rest_wins' => 0,
                'graphql_wins' => 0
            ];
        }
    }

    private function getChartData()
    {
        // Mengambil semua data pengujian dan mengelompokkan berdasarkan query_id
        $tests = RequestLog::select('query_id', 'rest_response_time_ms', 'graphql_response_time_ms')
            ->get()
            ->groupBy('query_id');

        $labels = [];
        $restTimes = [];
        $graphqlTimes = [];

        // Menghitung rata-rata untuk setiap query_id
        foreach ($tests as $queryId => $queryTests) {
            $labels[] = $queryId;
            
            // Hitung rata-rata waktu respons REST
            $avgRestTime = $queryTests->avg('rest_response_time_ms');
            $restTimes[] = round($avgRestTime, 2);
            
            // Hitung rata-rata waktu respons GraphQL
            $avgGraphqlTime = $queryTests->avg('graphql_response_time_ms');
            $graphqlTimes[] = round($avgGraphqlTime, 2);
        }

        // Urutkan data berdasarkan query_id
        array_multisort($labels, SORT_NATURAL, $restTimes, $graphqlTimes);

        return [
            'labels' => $labels,
            'rest_times' => $restTimes,
            'graphql_times' => $graphqlTimes
        ];
    }

    private function getWindowsCpuUsage()
    {
        try {
            if (!class_exists('COM')) {
                // Fallback menggunakan command line
                $cmd = "wmic cpu get loadpercentage /value";
                $output = shell_exec($cmd);
                if (preg_match("/LoadPercentage=(\d+)/", $output, $matches)) {
                    return (int)$matches[1];
                }
                return 0;
            }

            $wmi = new \COM('Winmgmts://');
            $cpu = $wmi->ExecQuery('SELECT LoadPercentage FROM Win32_Processor');
            
            $cpu_load = 0;
            foreach ($cpu as $obj) {
                $cpu_load = $obj->LoadPercentage;
            }
            return $cpu_load;
        } catch (\Exception $e) {
            \Log::error('Error getting CPU usage: ' . $e->getMessage());
            return 0;
        }
    }

    private function getWindowsMemoryUsage()
    {
        try {
            if (!class_exists('COM')) {
                // Fallback menggunakan command line
                $cmd = "wmic OS get FreePhysicalMemory,TotalVisibleMemorySize /Value";
                $output = shell_exec($cmd);
                
                preg_match("/TotalVisibleMemorySize=(\d+)/", $output, $total_matches);
                preg_match("/FreePhysicalMemory=(\d+)/", $output, $free_matches);
                
                if (isset($total_matches[1]) && isset($free_matches[1])) {
                    $total = (int)$total_matches[1];
                    $free = (int)$free_matches[1];
                    return ($total - $free) / $total * 100;
                }
                return 0;
            }

            $wmi = new \COM('Winmgmts://');
            $mem = $wmi->ExecQuery('SELECT FreePhysicalMemory,TotalVisibleMemorySize FROM Win32_OperatingSystem');
            
            foreach ($mem as $obj) {
                $total = $obj->TotalVisibleMemorySize;
                $free = $obj->FreePhysicalMemory;
                return ($total - $free) / $total * 100;
            }
            return 0;
        } catch (\Exception $e) {
            \Log::error('Error getting memory usage: ' . $e->getMessage());
            return 0;
        }
    }

    private function getWindowsDiskUsage()
    {
        try {
            $drive = 'C:';
            $total = disk_total_space($drive);
            $free = disk_free_space($drive);
            return ($total - $free) / $total * 100;
        } catch (\Exception $e) {
            \Log::error('Error getting disk usage: ' . $e->getMessage());
            return 0;
        }
    }

    private function getLinuxCpuUsage()
    {
        $load = sys_getloadavg();
        return $load[0] * 100;
    }

    private function getLinuxMemoryUsage()
    {
        $free = shell_exec('free');
        $free = (string)trim($free);
        $free_arr = explode("\n", $free);
        $mem = explode(" ", $free_arr[1]);
        $mem = array_filter($mem);
        $mem = array_merge($mem);
        return $mem[2]/$mem[1]*100;
    }

    private function getLinuxDiskUsage()
    {
        $disk_total = disk_total_space('/');
        $disk_free = disk_free_space('/');
        return ($disk_total - $disk_free) / $disk_total * 100;
    }
    
    /**
     * Log integrated API test result to RequestLog for history tracking
     */
    private function logIntegratedApiResult($result)
    {
        try {
            RequestLog::create([
                'query_id' => $result['query_id'],
                'endpoint' => "Integrated Query {$result['query_id']}",
                'cache_status' => $result['cache_used'] ? 'CACHE_USED' : 'MISS',
                'winner_api' => $result['winner_api'] ?? 'integrated',
                'rest_response_time_ms' => $result['rest_response_time_ms'] ?? 0,
                'graphql_response_time_ms' => $result['graphql_response_time_ms'] ?? 0,
                'rest_succeeded' => $result['rest_succeeded'] ?? false,
                'graphql_succeeded' => $result['graphql_succeeded'] ?? false,
                'response_body' => json_encode([
                    'integrated_api' => true,
                    'execution_mode' => $result['execution_mode'] ?? 'unknown',
                    'selected_api' => $result['selected_api'] ?? 'both',
                    'cache_used' => $result['cache_used'] ?? false,
                    'total_response_time_ms' => $result['total_response_time_ms'] ?? 0,
                    'rest_data' => $result['response_data']['rest'] ?? null,
                    'graphql_data' => $result['response_data']['graphql'] ?? null
                ])
            ]);
            
            Log::info('Logged integrated API result to RequestLog', [
                'query_id' => $result['query_id'],
                'execution_mode' => $result['execution_mode'] ?? 'unknown'
            ]);
        } catch (\Exception $e) {
            Log::error('Error logging integrated API result: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    /**
     * Log single API test result (REST or GraphQL) to RequestLog for history tracking
     */
    private function logSingleApiResult($queryId, $apiType, $result)
    {
        try {
            RequestLog::create([
                'query_id' => $queryId,
                'endpoint' => "{$apiType} Query {$queryId}",
                'cache_status' => 'MISS', // Single API tests don't use cache
                'winner_api' => $result['succeeded'] ? $apiType : 'none',
                'rest_response_time_ms' => $apiType === 'rest' ? $result['response_time_ms'] : null,
                'graphql_response_time_ms' => $apiType === 'graphql' ? $result['response_time_ms'] : null,
                'rest_succeeded' => $apiType === 'rest' ? $result['succeeded'] : false,
                'graphql_succeeded' => $apiType === 'graphql' ? $result['succeeded'] : false,
                'response_body' => json_encode([
                    'single_api' => true,
                    'api_type' => $apiType,
                    'response_time_ms' => $result['response_time_ms'],
                    'succeeded' => $result['succeeded'],
                    'error' => $result['error'] ?? null,
                    'response_data' => $result['response'] ?? null
                ])
            ]);
            
            Log::info('Logged single API result to RequestLog', [
                'query_id' => $queryId,
                'api_type' => $apiType,
                'succeeded' => $result['succeeded']
            ]);
        } catch (\Exception $e) {
            Log::error('Error logging single API result: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    /**
     * Get API comparison data for dashboard
     */
    public function getApiComparisonData(Request $request)
    {
        try {
            $queryId = $request->input('query_id', 'Q1');
            
            // Ambil data dari database performance_metrics
            $restMetrics = PerformanceMetric::where('query_id', $queryId)
                ->where('api_type', 'rest')
                ->latest()
                ->take(20)
                ->get();
                
            $graphqlMetrics = PerformanceMetric::where('query_id', $queryId)
                ->where('api_type', 'graphql')
                ->latest()
                ->take(20)
                ->get();
                
            $integratedMetrics = PerformanceMetric::where('query_id', $queryId)
                ->where('api_type', 'integrated')
                ->latest()
                ->take(20)
                ->get();
            
            // Hitung rata-rata dari data real
            $comparison = [
                'rest' => [
                    'response_time' => $restMetrics->avg('avg_response_time_ms') ?: 0,
                    'cpu_usage' => $restMetrics->avg('cpu_usage_percent') ?: 0,
                    'memory_usage' => $restMetrics->avg('memory_usage_percent') ?: 0
                ],
                'graphql' => [
                    'response_time' => $graphqlMetrics->avg('avg_response_time_ms') ?: 0,
                    'cpu_usage' => $graphqlMetrics->avg('cpu_usage_percent') ?: 0,
                    'memory_usage' => $graphqlMetrics->avg('memory_usage_percent') ?: 0
                ],
                'integrated' => [
                    'response_time' => $integratedMetrics->avg('avg_response_time_ms') ?: 0,
                    'cpu_usage' => $integratedMetrics->avg('cpu_usage_percent') ?: 0,
                    'memory_usage' => $integratedMetrics->avg('memory_usage_percent') ?: 0
                ]
            ];
            
            // Round all values
            foreach ($comparison as &$apiData) {
                $apiData['response_time'] = round($apiData['response_time'], 2);
                $apiData['cpu_usage'] = round($apiData['cpu_usage'], 2);
                $apiData['memory_usage'] = round($apiData['memory_usage'], 2);
            }
            
            // Ambil data historis untuk chart
            $historicalData = $this->getHistoricalComparisonData($queryId);
            
            return response()->json([
                'success' => true,
                'data' => $comparison,
                'historical' => $historicalData,
                'query_id' => $queryId,
                'data_source' => 'database', // Indikator bahwa data dari database
                'has_real_data' => !($restMetrics->isEmpty() && $graphqlMetrics->isEmpty() && $integratedMetrics->isEmpty())
            ]);
            
        } catch (\Exception $e) {
            Log::error('Exception in getApiComparisonData: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get historical comparison data for charts
     */
    private function getHistoricalComparisonData($queryId)
    {
        try {
            // Ambil data historis dari performance_metrics
            $restData = PerformanceMetric::where('query_id', $queryId)
                ->where('api_type', 'rest')
                ->orderBy('created_at')
                ->take(50)
                ->get(['avg_response_time_ms', 'cpu_usage_percent', 'memory_usage_percent', 'created_at']);
                
            $graphqlData = PerformanceMetric::where('query_id', $queryId)
                ->where('api_type', 'graphql')
                ->orderBy('created_at')
                ->take(50)
                ->get(['avg_response_time_ms', 'cpu_usage_percent', 'memory_usage_percent', 'created_at']);
                
            $integratedData = PerformanceMetric::where('query_id', $queryId)
                ->where('api_type', 'integrated')
                ->orderBy('created_at')
                ->take(50)
                ->get(['avg_response_time_ms', 'cpu_usage_percent', 'memory_usage_percent', 'created_at']);
            
            return [
                'rest' => $restData,
                'graphql' => $graphqlData,
                'integrated' => $integratedData
            ];
        } catch (\Exception $e) {
            Log::error('Error getting historical data: ' . $e->getMessage());
            return [
                'rest' => collect(),
                'graphql' => collect(),
                'integrated' => collect()
            ];
        }
    }

    public function getTestDetails(Request $request)
    {
        try {
            $queryId = $request->input('query_id');
            $testDate = $request->input('test_date');
            $apiType = $request->input('api_type', 'all');

            if (!$queryId || !$testDate) {
                return response()->json([
                    'success' => false,
                    'error' => 'Query ID dan tanggal pengujian diperlukan'
                ], 400);
            }

            // Ambil detail pengujian berdasarkan query_id dan tanggal
            $query = RequestLog::where('query_id', $queryId)
                ->whereDate('created_at', $testDate);

            // Filter berdasarkan API type jika tidak 'all'
            if ($apiType !== 'all') {
                switch ($apiType) {
                    case 'rest':
                        $query->where('rest_response_time_ms', '>', 0);
                        break;
                    case 'graphql':
                        $query->where('graphql_response_time_ms', '>', 0);
                        break;
                    case 'integrated':
                        $query->where('response_body', 'LIKE', '%"integrated_api":true%');
                        break;
                }
            }

            $testDetails = $query->orderBy('created_at', 'desc')->get();
                
            if ($testDetails->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Data pengujian tidak ditemukan'
                ], 404);
            }
            
            // Hitung statistik dengan perbaikan
            $totalTests = $testDetails->count();
            $successfulTests = $testDetails->filter(function($test) {
                return $test->rest_succeeded || $test->graphql_succeeded;
            })->count();
            
            $stats = [
                'total_tests' => $totalTests,
                'avg_rest_time' => round($testDetails->avg('rest_response_time_ms'), 2),
                'avg_graphql_time' => round($testDetails->avg('graphql_response_time_ms'), 2),
                'rest_wins' => $testDetails->where('winner_api', 'rest')->count(),
                'graphql_wins' => $testDetails->where('winner_api', 'graphql')->count(),
                'cache_hits' => $testDetails->where('cache_status', 'HIT')->count(),
                'success_rate' => $totalTests > 0 ? round(($successfulTests / $totalTests) * 100, 2) : 0
            ];
            
            return response()->json([
                'success' => true,
                'data' => $testDetails,
                'stats' => $stats
            ]);
            
        } catch (\Exception $e) {
            Log::error('Exception in getTestDetails: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'error' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get queries that have data in database for comparison dropdown
     */
    private function getQueriesWithData()
    {
        try {
            // Ambil query_id yang unique dari PerformanceMetric
            $queriesFromPerformance = PerformanceMetric::select('query_id')
                ->distinct()
                ->pluck('query_id')
                ->toArray();
            
            // Ambil query_id yang unique dari RequestLog
            $queriesFromRequest = RequestLog::select('query_id')
                ->distinct()
                ->pluck('query_id')
                ->toArray();
            
            // Gabungkan dan ambil unique
            $availableQueryIds = array_unique(array_merge($queriesFromPerformance, $queriesFromRequest));
            
            // Sort query IDs
            sort($availableQueryIds);
            
            // Ambil deskripsi query
            $allQueries = $this->getAvailableQueries();
            $result = [];
            
            foreach ($availableQueryIds as $queryId) {
                if (isset($allQueries[$queryId])) {
                    $result[$queryId] = $allQueries[$queryId];
                }
            }
            
            return $result;
        } catch (\Exception $e) {
            Log::error('Error getting queries with data: ' . $e->getMessage());
            return [];
        }
    }
}
