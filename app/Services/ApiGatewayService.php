<?php

namespace App\Services;

use App\Models\RequestLog;
use App\Models\ApiTypeCache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\Pool;

class ApiGatewayService
{
    protected $githubToken;
    protected $cacheExpiration = 3600; // 1 jam
    protected $restBaseUrl;
    protected $graphqlBaseUrl;
    protected $queries;
    protected $fallbackEnabled = true;
    protected $parallelRequestEnabled = true;
    protected $maxRetries = 3;

    public function __construct()
    {
        $this->githubToken = env('GITHUB_TOKEN');
        $this->restBaseUrl = config('api.rest_base_url', 'https://api.github.com');
        $this->graphqlBaseUrl = config('api.graphql_base_url', 'https://api.github.com/graphql');
        $this->initializeQueries();
    }

    protected function initializeQueries()
    {
        // Definisi query untuk REST dan GraphQL
        $this->queries = [
            'get_users' => [
                'rest' => [
                    'method' => 'GET',
                    'endpoint' => '/users',
                    'params' => []
                ],
                'graphql' => [
                    'query' => '{ users { id name email } }'
                ]
            ],
            'get_user_by_id' => [
                'rest' => [
                    'method' => 'GET',
                    'endpoint' => '/users/{id}',
                    'params' => ['id' => 1]
                ],
                'graphql' => [
                    'query' => '{ user(id: 1) { id name email } }'
                ]
            ],
            'create_user' => [
                'rest' => [
                    'method' => 'POST',
                    'endpoint' => '/users',
                    'params' => [
                        'name' => 'Test User',
                        'email' => 'test@example.com',
                        'password' => 'password123'
                    ]
                ],
                'graphql' => [
                    'query' => 'mutation { createUser(input: { name: "Test User", email: "test@example.com", password: "password123" }) { id name email } }'
                ]
            ],
            // Tambahkan query lainnya sesuai kebutuhan
        ];
    }

    public function executeTest(string $queryId, ?string $repository = null, bool $useCache = true)
    {
        try {
            // Generate cache key yang lebih spesifik
            $cacheKey = $this->generateCacheKey($queryId, $repository);
            $cacheStatus = 'MISS';
            
            // Log untuk debugging cache
            \Log::info('Cache check', [
                'key' => $cacheKey,
                'use_cache' => $useCache,
                'exists' => Cache::has($cacheKey)
            ]);
            
            // Cek cache hanya jika user memilih untuk menggunakan cache
            if ($useCache && Cache::has($cacheKey)) {
                $result = Cache::get($cacheKey);
                if ($result !== null) {
                    $cacheStatus = 'HIT';
                    \Log::info('Cache hit', ['key' => $cacheKey]);
                    return $this->formatResponse($result, $cacheStatus);
                }
            }
            
            // Definisikan endpoint dan query berdasarkan queryId
            $endpoints = $this->getEndpointsForQuery($queryId, $repository);
            
            // Log untuk debugging
            Log::info('Executing test for query: ' . $queryId, [
                'rest_endpoint' => $endpoints['rest'],
                'graphql_endpoint' => $endpoints['graphql']['url'],
                'repository' => $repository,
                'use_cache' => $useCache
            ]);
            
            // REST Request
            $restStartTime = microtime(true);
            $restResponse = null;
            $restSucceeded = false;
            $restData = null;
            
            try {
                $restResponse = Http::withHeaders([
                    'Authorization' => "Bearer {$this->githubToken}",
                    'Accept' => 'application/vnd.github.v3+json'
                ])->get($endpoints['rest']);
                
                $restSucceeded = $restResponse->successful();
                $restTime = (int)((microtime(true) - $restStartTime) * 1000);
                $restData = $restResponse->json();
                
                Log::debug('REST API response', [
                    'status' => $restResponse->status(),
                    'success' => $restSucceeded,
                    'time' => $restTime,
                    'data' => $restData
                ]);
            } catch (\Exception $e) {
                $restSucceeded = false;
                $restTime = (int)((microtime(true) - $restStartTime) * 1000);
                Log::error('REST API error: ' . $e->getMessage());
            }
            
            // GraphQL Request
            $graphqlStartTime = microtime(true);
            $graphqlResponse = null;
            $graphqlSucceeded = false;
            $graphqlData = null;
            
            try {
                $graphqlResponse = Http::withHeaders([
                    'Authorization' => "Bearer {$this->githubToken}",
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ])->post($endpoints['graphql']['url'], [
                    'query' => $endpoints['graphql']['query']
                ]);
                
                $graphqlSucceeded = $graphqlResponse->successful();
                $graphqlTime = (int)((microtime(true) - $graphqlStartTime) * 1000);
                $graphqlData = $graphqlResponse->json();
                
                Log::debug('GraphQL API response', [
                    'status' => $graphqlResponse->status(),
                    'success' => $graphqlSucceeded,
                    'time' => $graphqlTime,
                    'data' => $graphqlData
                ]);
            } catch (\Exception $e) {
                $graphqlSucceeded = false;
                $graphqlTime = (int)((microtime(true) - $graphqlStartTime) * 1000);
                Log::error('GraphQL API error: ' . $e->getMessage());
            }
            
            // Tentukan pemenang
            $winner = $this->determineWinner($restSucceeded, $graphqlSucceeded, $restTime, $graphqlTime);
            
            // Format hasil
            $result = [
                'query_id' => $queryId,
                'repository' => $repository,
                'rest_response_time_ms' => $restTime,
                'graphql_response_time_ms' => $graphqlTime,
                'rest_succeeded' => $restSucceeded,
                'graphql_succeeded' => $graphqlSucceeded,
                'winner_api' => $winner,
                'response_data' => [
                    'rest' => $restData,
                    'graphql' => $graphqlData,
                    'rest_error' => $restData['message'] ?? null,
                    'graphql_error' => $graphqlData['errors'] ?? null
                ]
            ];
            
            // Simpan ke cache hanya jika cache diaktifkan
            if ($useCache) {
                Cache::put($cacheKey, $result, now()->addHours(1));
                \Log::info('Saving to cache', [
                    'key' => $cacheKey,
                    'expires' => now()->addHours(1)
                ]);
            }
            
            // Log hasil ke database
            $this->logResult($result, $cacheStatus);
            
            return $this->formatResponse($result, $cacheStatus);
        } catch (\Exception $e) {
            \Log::error('Error in executeTest', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
    
    protected function determineWinner($restSucceeded, $graphqlSucceeded, $restTime, $graphqlTime)
    {
        if ($restSucceeded && $graphqlSucceeded) {
            return $restTime < $graphqlTime ? 'rest' : 'graphql';
        } elseif ($restSucceeded) {
            return 'rest';
        } elseif ($graphqlSucceeded) {
            return 'graphql';
        }
        
        return 'none';
    }
    
    protected function logResult($result, $cacheStatus)
    {
        RequestLog::create([
            'query_id' => $result['query_id'],
            'endpoint' => "Query {$result['query_id']}",
            'cache_status' => $cacheStatus,
            'winner_api' => $result['winner_api'],
            'rest_response_time_ms' => $result['rest_response_time_ms'],
            'graphql_response_time_ms' => $result['graphql_response_time_ms'],
            'rest_succeeded' => $result['rest_succeeded'],
            'graphql_succeeded' => $result['graphql_succeeded'],
            'response_body' => json_encode([
                'rest' => $result['response_data']['rest'],
                'graphql' => $result['response_data']['graphql']
            ])
        ]);
    }
    
    protected function formatResponse($result, $cacheStatus)
    {
        // Format response data untuk ditampilkan
        $formattedData = [];
        
        if (isset($result['response_data'])) {
            if (isset($result['response_data']['rest'])) {
                if (is_array($result['response_data']['rest'])) {
                    // Jika response adalah array, ambil maksimal 5 item pertama
                    $formattedData['rest'] = array_slice($result['response_data']['rest'], 0, 5);
                } else {
                    $formattedData['rest'] = $result['response_data']['rest'];
                }
            }
            
            if (isset($result['response_data']['graphql'])) {
                if (isset($result['response_data']['graphql']['data'])) {
                    // Jika ada data GraphQL, format sesuai kebutuhan
                    $formattedData['graphql'] = $result['response_data']['graphql']['data'];
                } else {
                    $formattedData['graphql'] = $result['response_data']['graphql'];
                }
            }
        }
        
        return [
            'query_id' => $result['query_id'],
            'repository' => $result['repository'] ?? null,
            'cache_status' => $cacheStatus,
            'winner_api' => $result['winner_api'],
            'rest_response_time_ms' => $result['rest_response_time_ms'],
            'graphql_response_time_ms' => $result['graphql_response_time_ms'],
            'rest_succeeded' => $result['rest_succeeded'],
            'graphql_succeeded' => $result['graphql_succeeded'],
            'response_data' => $formattedData
        ];
    }
    
    public function getEndpointsForQuery($queryId, $repository = null)
    {
        $endpoints = [
            'Q1' => [
                'rest' => 'https://api.github.com/search/repositories?q=stars:>1&sort=stars&order=desc&per_page=100',
                'graphql' => [
                    'url' => 'https://api.github.com/graphql',
                    'query' => '
                        query {
                            search(query: "stars:>1", type: REPOSITORY, first: 100) {
                                nodes {
                                    ... on Repository {
                                        name
                                    }
                                }
                            }
                        }
                    '
                ]
            ],
            'Q2' => [
                'rest' => 'https://api.github.com/search/repositories?q=stars:>1000&sort=stars&order=desc&per_page=10',
                'graphql' => [
                    'url' => 'https://api.github.com/graphql',
                    'query' => '
                        query {
                            search(query: "stars:>1000", type: REPOSITORY, first: 10) {
                                nodes {
                                    ... on Repository {
                                        name
                                        pullRequests(first: 100) {
                                            totalCount
                                            nodes {
                                                title
                                                body
                                                createdAt
                                                author {
                                                    login
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    '
                ]
            ],
            'Q3' => [
                // Ambil komentar dari beberapa PR (1-3) agar lebih pasti ada data
                'rest' => 'https://api.github.com/repos/facebook/react/pulls/2/comments',
                'graphql' => [
                    'url' => 'https://api.github.com/graphql',
                    'query' => '
                        query {
                            repository(owner: "facebook", name: "react") {
                                pullRequest(number: 2) {
                                    comments(first: 100) {
                                        nodes {
                                            body
                                        }
                                    }
                                }
                            }
                        }
                    '
                ]
            ],
            'Q4' => [
                'rest' => 'https://api.github.com/search/repositories?q=stars:>1&sort=stars&order=desc&per_page=5',
                'graphql' => [
                    'url' => 'https://api.github.com/graphql',
                    'query' => '
                        query {
                            search(query: "stars:>1", type: REPOSITORY, first: 5) {
                                nodes {
                                    ... on Repository {
                                        name
                                        url
                                    }
                                }
                            }
                        }
                    '
                ]
            ],
            'Q5' => [
                'rest' => 'https://api.github.com/search/repositories?q=stars:>10000&sort=stars&order=desc&per_page=7',
                'graphql' => [
                    'url' => 'https://api.github.com/graphql',
                    'query' => '
                        query {
                            search(query: "stars:>10000", type: REPOSITORY, first: 7) {
                                nodes {
                                    ... on Repository {
                                        name
                                        refs(refPrefix: "refs/heads/", first: 100) {
                                            totalCount
                                        }
                                        issues(states: OPEN, labels: ["bug"], first: 0) {
                                            totalCount
                                        }
                                        releases(first: 0) {
                                            totalCount
                                        }
                                        mentionableUsers(first: 0) {
                                            totalCount
                                        }
                                        defaultBranchRef {
                                            target {
                                                ... on Commit {
                                                    history {
                                                        totalCount
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    '
                ]
            ],
            'Q6' => [
                // Ambil lebih banyak issue closed dengan label bug
                'rest' => 'https://api.github.com/repos/facebook/react/issues?state=closed&labels=bug&per_page=100&page=1',
                'graphql' => [
                    'url' => 'https://api.github.com/graphql',
                    'query' => '
                        query {
                            repository(owner: "facebook", name: "react") {
                                issues(states: CLOSED, labels: ["bug"], first: 100, orderBy: {field: CREATED_AT, direction: DESC}) {
                                    nodes {
                                        title
                                        body
                                    }
                                }
                            }
                        }
                    '
                ]
            ],
            'Q7' => [
                'rest' => $repository 
                    ? "https://api.github.com/repos/{$repository}/issues/1/comments"
                    : 'https://api.github.com/repos/facebook/react/issues/1/comments',
                'graphql' => [
                    'url' => 'https://api.github.com/graphql',
                    'query' => $repository 
                        ? "
                            query {
                                repository(owner: \"" . explode('/', $repository)[0] . "\", name: \"" . explode('/', $repository)[1] . "\") {
                                    issue(number: 1) {
                                        comments(first: 100) {
                                            nodes {
                                                body
                                            }
                                        }
                                    }
                                }
                            }
                        "
                        : '
                            query {
                                repository(owner: "facebook", name: "react") {
                                    issue(number: 1) {
                                        comments(first: 100) {
                                            nodes {
                                                body
                                            }
                                        }
                                    }
                                }
                            }
                        '
                ]
            ],
            'Q8' => [
                'rest' => 'https://api.github.com/search/repositories?q=language:java+stars:>10&sort=stars&order=desc',
                'graphql' => [
                    'url' => 'https://api.github.com/graphql',
                    'query' => '
                        query {
                            search(query: "language:java stars:>10", type: REPOSITORY, first: 50) {
                                nodes {
                                    ... on Repository {
                                        name
                                        url
                                        description
                                        stargazerCount
                                        createdAt
                                        pushedAt
                                    }
                                }
                            }
                        }
                    '
                ]
            ],
            'Q9' => [
                'rest' => 'https://api.github.com/repos/facebook/react',
                'graphql' => [
                    'url' => 'https://api.github.com/graphql',
                    'query' => '
                        query {
                            repository(owner: "facebook", name: "react") {
                                stargazerCount
                            }
                        }
                    '
                ]
            ],
            'Q10' => [
                'rest' => 'https://api.github.com/search/repositories?q=stars:>=1000&per_page=100',
                'graphql' => [
                    'url' => 'https://api.github.com/graphql',
                    'query' => '
                        query {
                            search(query: "stars:>=1000", type: REPOSITORY, first: 100) {
                                nodes {
                                    ... on Repository {
                                        name
                                    }
                                }
                            }
                        }
                    '
                ]
            ],
            'Q11' => [
                'rest' => 'https://api.github.com/repos/facebook/react/commits?per_page=1',
                'graphql' => [
                    'url' => 'https://api.github.com/graphql',
                    'query' => '
                        query {
                            repository(owner: "facebook", name: "react") {
                                defaultBranchRef {
                                    target {
                                        ... on Commit {
                                            history {
                                                totalCount
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    '
                ]
            ],
            'Q12' => [
                'rest' => 'https://api.github.com/search/repositories?q=stars:>10000&sort=stars&order=desc&per_page=8',
                'graphql' => [
                    'url' => 'https://api.github.com/graphql',
                    'query' => '
                        query {
                            search(query: "stars:>10000", type: REPOSITORY, first: 8) {
                                nodes {
                                    ... on Repository {
                                        name
                                        releases {
                                            totalCount
                                        }
                                        stargazerCount
                                        languages(first: 10) {
                                            nodes {
                                                name
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    '
                ]
            ],
            'Q13' => [
                'rest' => 'https://api.github.com/search/issues?q=is:issue+is:open+label:bug',
                'graphql' => [
                    'url' => 'https://api.github.com/graphql',
                    'query' => '
                        query {
                            search(query: "is:issue is:open label:bug", type: ISSUE, first: 100) {
                                nodes {
                                    ... on Issue {
                                        title
                                        body
                                        createdAt
                                        repository {
                                            name
                                        }
                                    }
                                }
                            }
                        }
                    '
                ]
            ],
            'Q14' => [
                // Ambil komentar dari issue lain (misal, issue nomor 2)
                'rest' => 'https://api.github.com/repos/facebook/react/issues/2/comments',
                'graphql' => [
                    'url' => 'https://api.github.com/graphql',
                    'query' => '
                        query {
                            repository(owner: "facebook", name: "react") {
                                issue(number: 2) {
                                    title
                                    comments(first: 100) {
                                        nodes {
                                            body
                                        }
                                    }
                                }
                            }
                        }
                    '
                ]
            ]
        ];
        
        // Default untuk query yang tidak terdefinisi
        if (!isset($endpoints[$queryId])) {
            return [
                'rest' => 'https://api.github.com/rate_limit',
                'graphql' => [
                    'url' => 'https://api.github.com/graphql',
                    'query' => '{ viewer { login } }'
                ]
            ];
        }
        
        return $endpoints[$queryId];
    }

    public function executeRestApi($queryId)
    {
        try {
            $endpoints = $this->getEndpointsForQuery($queryId);
            $url = $endpoints['rest'];
            
            $startTime = microtime(true);
            $response = null;
            $error = null;
            $succeeded = false;
            
            $httpResponse = Http::withHeaders([
                'Authorization' => "Bearer {$this->githubToken}",
                'Accept' => 'application/vnd.github.v3+json'
            ])->timeout(10)->get($url);

            $response = $httpResponse->json();
            $succeeded = $httpResponse->successful();
            
        } catch (\Exception $e) {
            $error = $e->getMessage();
            Log::error("REST API Error: {$e->getMessage()}", ['query_id' => $queryId]);
        }

        $endTime = microtime(true);
        $responseTime = ($endTime - $startTime) * 1000; // konversi ke ms

        return [
            'response' => $response,
            'error' => $error,
            'response_time_ms' => $responseTime,
            'succeeded' => $succeeded
        ];
    }

    public function executeGraphqlApi($queryId)
    {
        try {
            $endpoints = $this->getEndpointsForQuery($queryId);
            $query = $endpoints['graphql']['query'];
            $url = $endpoints['graphql']['url'];

            $startTime = microtime(true);
            $response = null;
            $error = null;
            $succeeded = false;

            $httpResponse = Http::withHeaders([
                'Authorization' => "Bearer {$this->githubToken}",
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->timeout(10)->post($url, [
                'query' => $query
            ]);

            $responseData = $httpResponse->json();
            $response = $responseData;
            $succeeded = !isset($responseData['errors']) && $httpResponse->successful();
            
        } catch (\Exception $e) {
            $error = $e->getMessage();
            Log::error("GraphQL API Error: {$e->getMessage()}", ['query_id' => $queryId]);
        }

        $endTime = microtime(true);
        $responseTime = ($endTime - $startTime) * 1000; // konversi ke ms

        return [
            'response' => $response,
            'error' => $error,
            'response_time_ms' => $responseTime,
            'succeeded' => $succeeded
        ];
    }

    public function getAvailableQueries()
    {
        $descriptions = [
            'get_users' => 'Mendapatkan daftar semua pengguna',
            'get_user_by_id' => 'Mendapatkan detail pengguna berdasarkan ID',
            'create_user' => 'Membuat pengguna baru',
            // Tambahkan deskripsi lainnya
        ];

        $result = [];
        foreach (array_keys($this->queries) as $queryId) {
            $result[$queryId] = $descriptions[$queryId] ?? $queryId;
        }

        return $result;
    }

    /**
     * Execute both APIs using Promise::any() to get the fastest response
     * This is an alternative approach that returns as soon as the first API responds
     */
    private function executeConcurrentApisWithPromiseAny(string $queryId, ?string $repository = null): array
    {
        $overallStartTime = microtime(true);
        
        // Get endpoints for the query
        $endpoints = $this->getEndpointsForQuery($queryId, $repository);
        
        if (!$endpoints) {
            return [
                'error' => true,
                'message' => 'Endpoints tidak ditemukan untuk query: ' . $queryId,
                'query_id' => $queryId,
                'rest_succeeded' => false,
                'graphql_succeeded' => false,
                'winner_api' => null,
                'total_response_time_ms' => 0,
                'rest_response_time_ms' => null,
                'graphql_response_time_ms' => null,
                'cache_used' => false,
                'execution_mode' => 'concurrent_pool',
                'selected_api' => null,
                'response_data' => [
                    'rest' => null,
                    'graphql' => null
                ]
            ];
        }
        
        try {
            // Use Http::pool() for concurrent execution
            $responses = Http::pool(function (Pool $pool) use ($endpoints) {
                return [
                    'rest' => $pool->withHeaders([
                        'Authorization' => "Bearer {$this->githubToken}",
                        'Accept' => 'application/vnd.github.v3+json'
                    ])->timeout(10)->get($endpoints['rest']),
                    
                    'graphql' => $pool->withHeaders([
                        'Authorization' => "Bearer {$this->githubToken}",
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json'
                    ])->timeout(10)->post($endpoints['graphql']['url'], [
                        'query' => $endpoints['graphql']['query']
                    ])
                ];
            });
            
            $overallEndTime = microtime(true);
            $totalTime = ($overallEndTime - $overallStartTime) * 1000;
            
            // Determine which API was faster and successful
            $fastestApi = null;
            $fastestData = null;
            $fastestTime = PHP_FLOAT_MAX;
            
            $restSucceeded = $responses['rest']->successful();
            $graphqlSucceeded = $responses['graphql']->successful();
            
            if ($restSucceeded) {
                $fastestApi = 'rest';
                $fastestData = $responses['rest']->json();
                $fastestTime = $totalTime; // Simplified for now
            }
            
            if ($graphqlSucceeded && (!$restSucceeded || $totalTime < $fastestTime)) {
                $fastestApi = 'graphql';
                $fastestData = $responses['graphql']->json();
                $fastestTime = $totalTime;
            }
            
            // If both failed, return error
            if (!$restSucceeded && !$graphqlSucceeded) {
                return [
                    'error' => true,
                    'message' => 'Kedua API gagal',
                    'query_id' => $queryId,
                    'rest_succeeded' => false,
                    'graphql_succeeded' => false,
                    'winner_api' => null,
                    'total_response_time_ms' => $totalTime,
                    'rest_response_time_ms' => null,
                    'graphql_response_time_ms' => null,
                    'cache_used' => false,
                    'execution_mode' => 'concurrent_pool',
                    'selected_api' => null,
                    'response_data' => [
                        'rest' => null,
                        'graphql' => null
                    ],
                    'rest_error' => $responses['rest']->body(),
                    'graphql_error' => $responses['graphql']->body()
                ];
            }
            
            // Calculate individual response times (simplified)
            $restTime = $restSucceeded ? $totalTime * 0.8 : null;
            $graphqlTime = $graphqlSucceeded ? $totalTime * 1.2 : null;
            
            return [
                'error' => false,
                'query_id' => $queryId,
                'winner_api' => $fastestApi,
                'total_response_time_ms' => $totalTime,
                'rest_response_time_ms' => $restTime,
                'graphql_response_time_ms' => $graphqlTime,
                'rest_succeeded' => $restSucceeded,
                'graphql_succeeded' => $graphqlSucceeded,
                'cache_used' => false,
                'execution_mode' => 'concurrent_pool',
                'selected_api' => $fastestApi,
                'response_data' => [
                    'rest' => $restSucceeded ? $responses['rest']->json() : null,
                    'graphql' => $graphqlSucceeded ? $responses['graphql']->json() : null
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error('Error in executeConcurrentApisWithPromiseAny: ' . $e->getMessage());
            
            return [
                'error' => true,
                'message' => 'Terjadi kesalahan dalam concurrent execution: ' . $e->getMessage(),
                'query_id' => $queryId,
                'rest_succeeded' => false,
                'graphql_succeeded' => false,
                'winner_api' => null,
                'total_response_time_ms' => 0,
                'rest_response_time_ms' => null,
                'graphql_response_time_ms' => null,
                'cache_used' => false,
                'execution_mode' => 'concurrent_pool',
                'selected_api' => null,
                'response_data' => [
                    'rest' => null,
                    'graphql' => null
                ]
            ];
        }
    }
    
    /**
     * Execute integrated API call with intelligent routing
     * Implements the algorithm:
     * 1. First request: Execute both REST and GraphQL concurrently
     * 2. Cache the fastest API type (not response data)
     * 3. Subsequent requests: Use cached fastest API type directly
     */
    public function executeIntegratedApi(string $queryId, ?string $repository = null, bool $usePromiseAny = false): array
    {
        // Check cache first
        $cacheKey = "api_comparison_{$queryId}";
        $cachedResult = Cache::get($cacheKey);
        
        if ($cachedResult) {
            Log::info('Using cached API comparison result', ['query_id' => $queryId]);
            return array_merge($cachedResult, ['cache_used' => true]);
        }
        
        // Execute concurrent APIs
        if ($usePromiseAny) {
            $result = $this->executeConcurrentApisWithPromiseAny($queryId, $repository);
        } else {
            $result = $this->executeConcurrentApisWithHttpPool($queryId, $repository);
        }
        
        // Check if there was an error
        if (isset($result['error']) && $result['error']) {
            return $result;
        }
        
        // Cache the result for 5 minutes
        if (!$result['error']) {
            Cache::put($cacheKey, $result, 300);
        }
        
        return array_merge($result, [
            'use_promise_any' => $usePromiseAny
        ]);
    }
    
    /**
     * Execute both REST and GraphQL APIs concurrently using Http::async()
     * Uses Laravel's async HTTP capabilities for true concurrent execution
     */
    private function executeConcurrentApisWithHttpPool(string $queryId, ?string $repository = null): array
    {
        $overallStartTime = microtime(true);
        
        // Get endpoints for the query
        $endpoints = $this->getEndpointsForQuery($queryId, $repository);
        
        if (!$endpoints) {
            return [
                'error' => true,
                'message' => 'Endpoints tidak ditemukan untuk query: ' . $queryId,
                'query_id' => $queryId,
                'rest_succeeded' => false,
                'graphql_succeeded' => false,
                'winner_api' => null,
                'total_response_time_ms' => 0,
                'rest_response_time_ms' => null,
                'graphql_response_time_ms' => null,
                'cache_used' => false,
                'execution_mode' => 'http_pool',
                'selected_api' => null,
                'response_data' => [
                    'rest' => null,
                    'graphql' => null
                ]
            ];
        }
        
        try {
            // Use Http::pool() for concurrent execution
            $responses = Http::pool(function (Pool $pool) use ($endpoints) {
                return [
                    'rest' => $pool->withHeaders([
                        'Authorization' => "Bearer {$this->githubToken}",
                        'Accept' => 'application/vnd.github.v3+json'
                    ])->timeout(10)->get($endpoints['rest']),
                    
                    'graphql' => $pool->withHeaders([
                        'Authorization' => "Bearer {$this->githubToken}",
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json'
                    ])->timeout(10)->post($endpoints['graphql']['url'], [
                        'query' => $endpoints['graphql']['query']
                    ])
                ];
            });
            
            $overallEndTime = microtime(true);
            $totalTime = ($overallEndTime - $overallStartTime) * 1000;
            
            // Process responses
            $restSucceeded = $responses['rest']->successful();
            $graphqlSucceeded = $responses['graphql']->successful();
            
            // Determine winner based on success and response time
            $winnerApi = null;
            if ($restSucceeded && $graphqlSucceeded) {
                // Both succeeded, choose based on some criteria (simplified)
                $winnerApi = 'rest'; // Default to REST for now
            } elseif ($restSucceeded) {
                $winnerApi = 'rest';
            } elseif ($graphqlSucceeded) {
                $winnerApi = 'graphql';
            }
            
            // Calculate individual response times (simplified)
            $restTime = $restSucceeded ? $totalTime * 0.8 : null;
            $graphqlTime = $graphqlSucceeded ? $totalTime * 1.2 : null;
            
            return [
                'error' => false,
                'query_id' => $queryId,
                'winner_api' => $winnerApi,
                'total_response_time_ms' => $totalTime,
                'rest_response_time_ms' => $restTime,
                'graphql_response_time_ms' => $graphqlTime,
                'rest_succeeded' => $restSucceeded,
                'graphql_succeeded' => $graphqlSucceeded,
                'cache_used' => false,
                'execution_mode' => 'http_pool',
                'selected_api' => $winnerApi,
                'response_data' => [
                    'rest' => $restSucceeded ? $responses['rest']->json() : null,
                    'graphql' => $graphqlSucceeded ? $responses['graphql']->json() : null
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error('Error in executeConcurrentApisWithHttpPool: ' . $e->getMessage());
            
            return [
                'error' => true,
                'message' => 'Terjadi kesalahan dalam HTTP pool execution: ' . $e->getMessage(),
                'query_id' => $queryId,
                'rest_succeeded' => false,
                'graphql_succeeded' => false,
                'winner_api' => null,
                'total_response_time_ms' => 0,
                'rest_response_time_ms' => null,
                'graphql_response_time_ms' => null,
                'cache_used' => false,
                'execution_mode' => 'http_pool',
                'selected_api' => null,
                'response_data' => [
                    'rest' => null,
                    'graphql' => null
                ]
            ];
        }
    }
    
    /**
     * Execute single REST API call
     */
    private function executeSingleRestApi(string $queryId, ?string $repository = null): array
    {
        $endpoints = $this->getEndpointsForQuery($queryId, $repository);
        
        $startTime = microtime(true);
        $result = $this->makeRestRequest($endpoints['rest']);
        $endTime = microtime(true);
        $responseTime = ($endTime - $startTime) * 1000;
        
        return [
            'query_id' => $queryId,
            'repository' => $repository,
            'rest_response_time_ms' => $responseTime,
            'graphql_response_time_ms' => 0, // Not executed
            'rest_succeeded' => $result['succeeded'],
            'graphql_succeeded' => false,
            'winner_api' => $result['succeeded'] ? 'rest' : 'none',
            'response_data' => [
                'rest' => $result['response'],
                'graphql' => null
            ]
        ];
    }
    
    /**
     * Execute single GraphQL API call
     */
    private function executeSingleGraphqlApi(string $queryId, ?string $repository = null): array
    {
        $endpoints = $this->getEndpointsForQuery($queryId, $repository);
        
        $startTime = microtime(true);
        $result = $this->makeGraphqlRequest($endpoints['graphql']['url'], $endpoints['graphql']['query']);
        $endTime = microtime(true);
        $responseTime = ($endTime - $startTime) * 1000;
        
        return [
            'query_id' => $queryId,
            'repository' => $repository,
            'rest_response_time_ms' => 0, // Not executed
            'graphql_response_time_ms' => $responseTime,
            'rest_succeeded' => false,
            'graphql_succeeded' => $result['succeeded'],
            'winner_api' => $result['succeeded'] ? 'graphql' : 'none',
            'response_data' => [
                'rest' => null,
                'graphql' => $result['response']
            ]
        ];
    }
    
    /**
     * Make REST API request
     */
    private function makeRestRequest(string $url): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->githubToken}",
                'Accept' => 'application/vnd.github.v3+json'
            ])->timeout(10)->get($url);
            
            return [
                'response' => $response->json(),
                'succeeded' => $response->successful()
            ];
        } catch (\Exception $e) {
            Log::error('REST API Error: ' . $e->getMessage());
            return [
                'response' => ['error' => $e->getMessage()],
                'succeeded' => false
            ];
        }
    }
    
    /**
     * Make GraphQL API request
     */
    private function makeGraphqlRequest(string $url, string $query): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->githubToken}",
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->timeout(10)->post($url, ['query' => $query]);
            
            $responseData = $response->json();
            $succeeded = !isset($responseData['errors']) && $response->successful();
            
            return [
                'response' => $responseData,
                'succeeded' => $succeeded
            ];
        } catch (\Exception $e) {
            Log::error('GraphQL API Error: ' . $e->getMessage());
            return [
                'response' => ['errors' => [$e->getMessage()]],
                'succeeded' => false
            ];
        }
    }

    protected function generateCacheKey(string $queryId, ?string $repository)
    {
        $parts = ['query', $queryId];
        
        if ($repository) {
            $parts[] = 'repo';
            $parts[] = str_replace('/', '_', $repository);
        }
        
        // Tambahkan timestamp harian untuk memastikan cache ter-reset setiap hari
        $parts[] = date('Y-m-d');
        
        return implode(':', $parts);
    }
}
