<?php

declare(strict_types=1);

namespace App\Services\Scrapers\Nike;

use Exception;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class NikeCategoryScraper {
    private const BASE_URL                     = 'https://www.nike.com';
    private const API_BASE_URL                 = 'https://api.nike.com/discover/product_wall/v1/marketplace/CA/language/en-GB/consumerChannelId/d9a5bc42-4b9c-4976-858a-f159cf99c647';
    private const DEFAULT_PATH                 = '/ca/w/sale-3yaep';
    private const DEFAULT_ATTRIBUTE_IDS         = '5b21a62a-0503-400c-8336-3ccfbff2a684';
    private const ITEMS_PER_PAGE                = 24;
    private const HTTP_TIMEOUT                  = 60;
    private const HTTP_CONNECT_TIMEOUT          = 30;
    private const MAX_RETRIES                   = 5;
    private const RETRY_DELAY_BASE              = 2;
    private const DEFAULT_CATEGORY_CONCURRENCY  = 5;
    private const GC_COLLECT_INTERVAL           = 10;
    private const CURL_MULTI_SELECT_TIMEOUT      = 0.1;
    private static bool $firstResponseSaved = false;

    /**
     * Collect all product detail page URLs from Nike sale category using their API.
     *
     * @param string|null $apiUrl Optional full API URL. If not provided, uses default sale category
     * @param int         $concurrency Number of API requests to fetch concurrently
     *
     * @return array Array of unique absolute product URLs
     */
    public function collectProductLinks (?string $apiUrl = null, int $concurrency = self::DEFAULT_CATEGORY_CONCURRENCY) : array {
        Log::info('Starting Nike category link collection', [
            'api_url'    => $apiUrl ?? 'default',
            'concurrency' => $concurrency,
        ]);

        $productLinks = [];

        // Build initial API URL
        $baseApiUrl = $apiUrl ?? $this->buildApiUrl(self::DEFAULT_PATH, self::DEFAULT_ATTRIBUTE_IDS, 0);

        usleep(500000);

        // Fetch first page
        $firstPageResponse = $this->fetchApiPage($baseApiUrl);
        if ( $firstPageResponse === null ) {
            Log::error('Failed to fetch first page', ['api_url' => $baseApiUrl]);

            return [];
        }

        if ( !self::$firstResponseSaved ) {
            $this->saveFirstPageResponse($firstPageResponse, $baseApiUrl);
            self::$firstResponseSaved = true;
        }

        // Extract links from first page
        $firstPageLinks = $this->extractProductLinks($firstPageResponse);
        $productLinks   = array_merge($productLinks, $firstPageLinks);
        unset($firstPageResponse);

        Log::info('Processed page 1 (anchor=0)', [
            'links_found'        => count($firstPageLinks),
            'total_links_so_far' => count($productLinks),
        ]);

        // Determine if we need to paginate
        // Continue paginating if we got a full page (24 items), or if we want to check for more
        // We'll continue until we get a page with 0 products
        $hasMorePages = count($firstPageLinks) > 0;
        $anchor       = self::ITEMS_PER_PAGE;

        // Always try to paginate if we got products (even if less than 24, there might be more)
        // The loop will stop naturally when we get 0 products
        if ( $hasMorePages ) {
            Log::info('Starting pagination', [
                'items_per_page' => self::ITEMS_PER_PAGE,
                'concurrency'    => $concurrency,
            ]);

            $batchIndex = 0;

            while ( $hasMorePages ) {
                $batchUrls = [];
                $anchors   = [];

                // Build batch of URLs
                for ( $i = 0; $i < $concurrency && $hasMorePages; $i++ ) {
                    $batchApiUrl = $this->buildApiUrl(self::DEFAULT_PATH, self::DEFAULT_ATTRIBUTE_IDS, $anchor);
                    $batchUrls[$anchor] = $batchApiUrl;
                    $anchors[]          = $anchor;
                    $anchor            += self::ITEMS_PER_PAGE;
                }

                $batchNumber = $batchIndex + 1;
                Log::info("Processing batch {$batchNumber}", [
                    'batch_size' => count($batchUrls),
                    'anchors'    => implode(', ', $anchors),
                ]);

                $batchResults = $this->fetchApiPageBatch($batchUrls);
                $batchHasMore = false;
                $totalLinksInBatch = 0;

                foreach ( $batchResults as $anchorValue => $result ) {
                    if ( $result['success'] && !empty($result['data']) ) {
                        $pageLinks    = $this->extractProductLinks($result['data']);
                        $productLinks = array_merge($productLinks, $pageLinks);
                        $totalLinksInBatch += count($pageLinks);
                        unset($batchResults[$anchorValue]['data']);

                        Log::info("Processed anchor={$anchorValue}", [
                            'links_found'        => count($pageLinks),
                            'total_links_so_far' => count($productLinks),
                        ]);

                        // If we got any products, there might be more
                        // Continue paginating as long as we get products (even if less than ITEMS_PER_PAGE)
                        if ( count($pageLinks) > 0 ) {
                            $batchHasMore = true;
                        }
                    } else {
                        Log::warning("Failed to fetch anchor={$anchorValue}", [
                            'api_url'    => $batchUrls[$anchorValue],
                            'error'      => $result['error'] ?? 'Unknown error',
                            'status_code' => $result['statusCode'] ?? null,
                        ]);
                    }
                }

                unset($batchResults, $batchUrls);

                // Only stop if we got zero products from the entire batch
                if ( $totalLinksInBatch === 0 ) {
                    $hasMorePages = false;
                    Log::info('No more products found in batch, pagination complete', [
                        'total_links_collected' => count($productLinks),
                    ]);
                } else {
                    // Continue paginating - we got some products, so there might be more
                    usleep(500000);
                }

                if ( $batchNumber % self::GC_COLLECT_INTERVAL === 0 ) {
                    gc_collect_cycles();
                }

                $batchIndex++;
            }
        }

        // First, remove exact duplicates
        $productLinks = array_values(array_unique($productLinks));

        // Then, deduplicate by base product path (remove color variants, keep only one per product)
        $productLinks = $this->deduplicateByBaseProduct($productLinks);

        Log::info('Nike category link collection completed', [
            'api_url'            => $baseApiUrl,
            'total_unique_links' => count($productLinks),
        ]);

        return $productLinks;
    }

    /**
     * Build API URL with anchor parameter.
     */
    private function buildApiUrl (string $path, string $attributeIds, int $anchor) : string {
        $queryParams = [
            'path'        => $path,
            'attributeIds' => $attributeIds,
            'queryType'   => 'PRODUCTS',
            'anchor'      => $anchor,
            'count'       => self::ITEMS_PER_PAGE,
        ];

        $queryString = http_build_query($queryParams);

        return self::API_BASE_URL . '?' . $queryString;
    }

    /**
     * Fetch API response from a URL with retry logic.
     */
    private function fetchApiPage (string $url) : ?array {
        $attempt       = 0;
        $lastException = null;

        while ( $attempt < self::MAX_RETRIES ) {
            $attempt++;

            try {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL            => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS      => 5,
                    CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
                    CURLOPT_CONNECTTIMEOUT => self::HTTP_CONNECT_TIMEOUT,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST  => 2,
                    CURLOPT_ENCODING       => 'gzip, deflate, br',
                    CURLOPT_TCP_KEEPALIVE  => 1,
                    CURLOPT_TCP_KEEPIDLE   => 300,
                    CURLOPT_TCP_KEEPINTVL  => 300,
                    CURLOPT_HTTPHEADER     => $this->getApiHeaders(),
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error    = curl_error($ch);
                curl_close($ch);

                if ( $error ) {
                    throw new RuntimeException("cURL error: {$error}");
                }

                if ( $httpCode >= 200 && $httpCode < 300 ) {
                    $data = json_decode($response, true);
                    if ( json_last_error() !== JSON_ERROR_NONE ) {
                        throw new RuntimeException('Invalid JSON response: ' . json_last_error_msg());
                    }

                    if ( $attempt > 1 ) {
                        Log::info('Successfully fetched API page after retry', ['url' => $url, 'attempts' => $attempt]);
                    }

                    return $data;
                }

                if ( $httpCode === 429 || $httpCode === 503 ) {
                    $waitTime = pow(self::RETRY_DELAY_BASE, $attempt) + 5;
                    Log::warning('Rate limited, waiting before retry', [
                        'url'          => $url,
                        'status_code'  => $httpCode,
                        'attempt'      => $attempt,
                        'wait_seconds' => $waitTime,
                    ]);
                    if ( $attempt < self::MAX_RETRIES ) {
                        sleep((int) $waitTime);
                        continue;
                    }
                }

                if ( $attempt < self::MAX_RETRIES ) {
                    $waitTime = pow(self::RETRY_DELAY_BASE, $attempt);
                    Log::warning('Non-200 HTTP response, retrying', [
                        'url'          => $url,
                        'status_code'  => $httpCode,
                        'attempt'      => $attempt,
                        'wait_seconds' => $waitTime,
                    ]);
                    sleep((int) $waitTime);
                    continue;
                }

                Log::error('Failed to fetch API page after all retries', [
                    'url'         => $url,
                    'status_code' => $httpCode,
                    'attempts'    => $attempt,
                ]);

                return null;

            } catch ( Exception $e ) {
                $lastException = $e;
                $errorMessage  = $e->getMessage();

                $isRetryable = false;
                if ( strpos($errorMessage, 'Operation timed out') !== false || strpos($errorMessage, 'cURL error 28') !== false || strpos($errorMessage, 'Connection') !== false || strpos($errorMessage, 'SSL') !== false || strpos($errorMessage, 'timeout') !== false ) {
                    $isRetryable = true;
                }

                if ( $isRetryable && $attempt < self::MAX_RETRIES ) {
                    $waitTime = pow(self::RETRY_DELAY_BASE, $attempt);
                    Log::warning('Retryable error on API fetch, retrying', [
                        'url'             => $url,
                        'error'           => $errorMessage,
                        'attempt'         => $attempt,
                        'wait_seconds'    => $waitTime,
                        'exception_class' => get_class($e),
                    ]);
                    sleep((int) $waitTime);
                    continue;
                }

                Log::error('Error fetching API page', [
                    'url'             => $url,
                    'error'           => $errorMessage,
                    'attempt'         => $attempt,
                    'is_retryable'    => $isRetryable,
                    'exception_class' => get_class($e),
                ]);

                if ( $attempt >= self::MAX_RETRIES ) {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * Fetch multiple API pages concurrently using multi-cURL.
     */
    private function fetchApiPageBatch (array $urls) : array {
        $results     = [];
        $multiHandle = curl_multi_init();
        $handles     = [];

        foreach ( $urls as $anchor => $url ) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::HTTP_CONNECT_TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST  => 2,
                CURLOPT_ENCODING       => 'gzip, deflate, br',
                CURLOPT_TCP_KEEPALIVE  => 1,
                CURLOPT_TCP_KEEPIDLE   => 300,
                CURLOPT_TCP_KEEPINTVL  => 300,
                CURLOPT_HTTPHEADER     => $this->getApiHeaders(),
            ]);

            curl_multi_add_handle($multiHandle, $ch);
            $handles[$anchor] = $ch;
            $results[$anchor] = [
                'success'    => false,
                'data'       => null,
                'error'      => null,
                'statusCode' => null,
            ];
        }

        $running = null;
        $mrc     = CURLM_OK;

        do {
            $mrc = curl_multi_exec($multiHandle, $running);

            if ( $running > 0 ) {
                $selectTimeout = curl_multi_select($multiHandle, self::CURL_MULTI_SELECT_TIMEOUT);
                if ( $selectTimeout === -1 ) {
                    usleep(10000);
                }
            }
        } while ( $running > 0 && $mrc === CURLM_OK );

        foreach ( $handles as $anchor => $ch ) {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);

            if ( $error ) {
                $results[$anchor]['error'] = $error;
            } elseif ( $httpCode >= 200 && $httpCode < 300 ) {
                $response = curl_multi_getcontent($ch);
                $data     = json_decode($response, true);

                if ( json_last_error() === JSON_ERROR_NONE ) {
                    $results[$anchor] = [
                        'success'    => true,
                        'data'       => $data,
                        'error'      => null,
                        'statusCode' => $httpCode,
                    ];
                } else {
                    $results[$anchor]['error'] = 'Invalid JSON: ' . json_last_error_msg();
                    $results[$anchor]['statusCode'] = $httpCode;
                }
            } else {
                $results[$anchor]['statusCode'] = $httpCode;
                $results[$anchor]['error']      = "HTTP {$httpCode}";
            }

            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }

        curl_multi_close($multiHandle);
        unset($handles, $multiHandle);

        return $results;
    }

    /**
     * Get API request headers.
     * Note: If cookies are required, add them to the headers array:
     * 'cookie: [your cookie string here]'
     */
    private function getApiHeaders () : array {
        $headers = [
            'accept: */*',
            'nike-api-caller-id: nike:dotcom:browse:wall.client:2.0',
            'anonymousid: 883921869A97FB05AB43A618D9030499',
            'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'referer: https://www.nike.com',
            'origin: https://www.nike.com',
            'accept-language: en-US,en;q=0.9',
            'accept-encoding: gzip, deflate, br',
            'connection: keep-alive',
            'sec-fetch-dest: empty',
            'sec-fetch-mode: cors',
            'sec-fetch-site: same-site',
        ];

        // Uncomment and add cookies if needed:
        // $headers[] = 'cookie: [include all cookies needed here]';

        return $headers;
    }

    /**
     * Extract product detail page URLs from API response.
     */
    private function extractProductLinks (array $apiResponse) : array {
        $links = [];

        try {
            if ( !isset($apiResponse['productGroupings']) || !is_array($apiResponse['productGroupings']) ) {
                Log::warning('Invalid API response structure: missing productGroupings', [
                    'response_keys' => array_keys($apiResponse),
                ]);

                return [];
            }

            foreach ( $apiResponse['productGroupings'] as $grouping ) {
                if ( !isset($grouping['products']) || !is_array($grouping['products']) ) {
                    continue;
                }

                foreach ( $grouping['products'] as $product ) {
                    // Check for pdpUrl.url (full URL) first, fallback to pdpUrl.path
                    if ( isset($product['pdpUrl']['url']) && !empty($product['pdpUrl']['url']) ) {
                        // Already a full URL
                        $links[] = $product['pdpUrl']['url'];
                    } elseif ( isset($product['pdpUrl']['path']) && !empty($product['pdpUrl']['path']) ) {
                        // Relative path, normalize to absolute URL
                        $absoluteUrl = $this->normalizeUrl($product['pdpUrl']['path']);
                        if ( $absoluteUrl ) {
                            $links[] = $absoluteUrl;
                        }
                    }
                }
            }

        } catch ( Exception $e ) {
            Log::error('Error extracting product links from API response', [
                'error' => $e->getMessage(),
            ]);
        }

        return $links;
    }

    /**
     * Normalize a URL path to an absolute URL.
     */
    private function normalizeUrl (string $urlPath) : ?string {
        if ( empty($urlPath) ) {
            return null;
        }

        // If already absolute, return as is
        if ( preg_match('/^https?:\/\//', $urlPath) ) {
            return $urlPath;
        }

        // Ensure path starts with /
        $urlPath = '/' . ltrim($urlPath, '/');
        $baseUrl = rtrim(self::BASE_URL, '/');

        return $baseUrl . $urlPath;
    }

    /**
     * Deduplicate product URLs by base product path.
     * Removes color variants, keeping only one URL per product.
     * 
     * URL structure: https://www.nike.com/ca/t/{product-slug}/{product-code}
     * Base product: https://www.nike.com/ca/t/{product-slug}/
     * 
     * @param array $urls Array of product URLs
     * @return array Deduplicated array with one URL per base product
     */
    private function deduplicateByBaseProduct (array $urls) : array {
        $baseProductMap = [];
        $deduplicated   = [];

        foreach ( $urls as $url ) {
            // Extract base product URL (everything before the last /)
            // Example: https://www.nike.com/ca/t/air-max-270-shoes-nnTrqDGR/AH8050-100
            // Base:     https://www.nike.com/ca/t/air-max-270-shoes-nnTrqDGR/
            $lastSlashPos = strrpos($url, '/');
            if ( $lastSlashPos === false ) {
                // No slash found, keep as is
                $deduplicated[] = $url;
                continue;
            }

            $baseProduct = substr($url, 0, $lastSlashPos + 1);

            // If we haven't seen this base product before, keep this URL
            if ( !isset($baseProductMap[$baseProduct]) ) {
                $baseProductMap[$baseProduct] = true;
                $deduplicated[]               = $url;
            }
            // Otherwise, skip this color variant
        }

        $removedCount = count($urls) - count($deduplicated);
        if ( $removedCount > 0 ) {
            Log::info('Deduplicated product URLs by base product', [
                'original_count' => count($urls),
                'deduplicated_count' => count($deduplicated),
                'removed_variants' => $removedCount,
            ]);
        }

        return $deduplicated;
    }

    /**
     * Save the first successfully fetched API response for debugging.
     */
    private function saveFirstPageResponse (array $response, string $apiUrl) : void {
        try {
            $debugDir = storage_path('logs/nike');
            if ( !file_exists($debugDir) ) {
                mkdir($debugDir, 0755, true);
            }

            $timestamp = now()->format('Y-m-d_H-i-s');
            $filename  = "first-page-response-{$timestamp}.json";
            $filePath  = $debugDir . '/' . $filename;

            file_put_contents($filePath, json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            Log::info('First page API response saved', [
                'api_url'   => $apiUrl,
                'file_path' => $filePath,
                'file_size' => filesize($filePath),
            ]);
        } catch ( Exception $e ) {
            Log::warning('Failed to save first page API response', [
                'api_url' => $apiUrl,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}

