<?php

declare(strict_types=1);

namespace App\Services\Scrapers\TommyHilfiger;

use Exception;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;

class TommyHilfigerCategoryScraper {
    private const BASE_URL                       = 'https://nl.tommy.com';
    private const PAGINATION_ITEM_COUNT_SELECTOR = 'div.Pagination_ItemCount__q8mzz[data-testid="Pagination-item-count"] span';
    private const PRODUCT_GRID_ITEM_SELECTOR     = 'li.ProductGrid_ProductGridItem__VJcst';
    private const PRODUCT_LINK_SELECTOR          = 'a.Link_Link__RX3bc.ProductGrid_ProductGridLink__AQ1KN';
    private const HTTP_TIMEOUT                   = 60;
    private const HTTP_CONNECT_TIMEOUT           = 30;
    private const MAX_RETRIES                    = 3;
    private const DEFAULT_CATEGORY_CONCURRENCY   = 5;
    private const GC_COLLECT_INTERVAL            = 10;
    private const CURL_MULTI_SELECT_TIMEOUT      = 0.1;
    private static bool $firstHtmlSaved = false;

    /**
     * Collect all product detail page URLs from a Tommy Hilfiger category page.
     *
     * @param string $categoryUrl The category URL
     * @param int    $concurrency Number of pages to fetch concurrently
     *
     * @return array Array of unique absolute product URLs
     */
    public function collectProductLinks (string $categoryUrl, int $concurrency = self::DEFAULT_CATEGORY_CONCURRENCY) : array {
        Log::info('Starting Tommy Hilfiger category link collection', [
            'category_url' => $categoryUrl,
            'concurrency'  => $concurrency,
        ]);

        $productLinks = [];
        $parsedUrl    = parse_url($categoryUrl);
        $basePath     = $parsedUrl['path'] ?? '';
        $queryParams  = [];
        if ( isset($parsedUrl['query']) ) {
            parse_str($parsedUrl['query'], $queryParams);
        }

        usleep(500000);

        $firstPageHtml = $this->fetchPage($categoryUrl);
        if ( $firstPageHtml === null ) {
            Log::error('Failed to fetch first page', ['category_url' => $categoryUrl]);

            return [];
        }

        if ( !self::$firstHtmlSaved ) {
            $this->saveFirstPageHtml($firstPageHtml, $categoryUrl);
            self::$firstHtmlSaved = true;
        }

        $paginationInfo = $this->parsePaginationInfo($firstPageHtml);
        if ( $paginationInfo === null ) {
            Log::warning('Could not parse pagination info, assuming single page', ['category_url' => $categoryUrl]);
            $totalPages = 1;
        } else {
            $totalPages = $paginationInfo['totalPages'];
            Log::info('Pagination info parsed', [
                'items_per_page' => $paginationInfo['itemsPerPage'],
                'total_items'    => $paginationInfo['totalItems'],
                'total_pages'    => $totalPages,
            ]);
        }

        $firstPageLinks = $this->extractProductLinks($firstPageHtml, self::BASE_URL);
        $productLinks   = array_merge($productLinks, $firstPageLinks);
        unset($firstPageHtml);
        Log::info('Processed page 1', [
            'links_found'        => count($firstPageLinks),
            'total_links_so_far' => count($productLinks),
        ]);

        if ( $totalPages > 1 ) {
            $remainingPages = range(2, $totalPages);
            $pageBatches    = array_chunk($remainingPages, $concurrency);
            $totalBatches   = count($pageBatches);

            Log::info('Starting batch processing of category pages', [
                'total_pages'     => $totalPages,
                'remaining_pages' => count($remainingPages),
                'batch_size'      => $concurrency,
                'total_batches'   => $totalBatches,
            ]);

            foreach ( $pageBatches as $batchIndex => $pageBatch ) {
                $batchNumber = $batchIndex + 1;

                Log::info("Processing batch {$batchNumber} of {$totalBatches}", [
                    'batch_size' => count($pageBatch),
                    'pages'      => implode(', ', $pageBatch),
                ]);

                $batchUrls = [];
                foreach ( $pageBatch as $page ) {
                    $batchUrls[$page] = $this->buildPageUrl($basePath, $page, $queryParams);
                }

                $batchResults = $this->fetchPageBatch($batchUrls);

                // Retry failed pages (especially 502 errors)
                $retryUrls = [];
                foreach ( $batchResults as $page => $result ) {
                    if ( !$result['success'] ) {
                        $httpCode = $result['statusCode'] ?? null;
                        $isRetryable = in_array($httpCode, [429, 502, 503, 504, 520, 521, 522, 523, 524]);
                        if ( $isRetryable && $httpCode !== null ) {
                            $retryUrls[$page] = $batchUrls[$page];
                        }
                    }
                }

                // Retry failed pages individually
                if ( !empty($retryUrls) ) {
                    Log::info('Retrying failed pages', [
                        'pages'       => array_keys($retryUrls),
                        'total_retries' => count($retryUrls),
                    ]);
                    foreach ( $retryUrls as $retryPage => $retryUrl ) {
                        usleep(500000); // Small delay between retries
                        $retryHtml = $this->fetchPage($retryUrl);
                        if ( $retryHtml !== null ) {
                            $batchResults[$retryPage] = [
                                'success'    => true,
                                'html'       => $retryHtml,
                                'error'      => null,
                                'statusCode' => 200,
                            ];
                            Log::info("Successfully retried page {$retryPage}");
                        }
                    }
                }

                foreach ( $batchResults as $page => $result ) {
                    if ( $result['success'] ) {
                        $pageLinks    = $this->extractProductLinks($result['html'], self::BASE_URL);
                        $productLinks = array_merge($productLinks, $pageLinks);
                        unset($batchResults[$page]['html']);

                        Log::info("Processed page {$page}", [
                            'links_found'        => count($pageLinks),
                            'total_links_so_far' => count($productLinks),
                        ]);
                    } else {
                        Log::warning("Failed to fetch page {$page}", [
                            'page_url'    => $batchUrls[$page],
                            'error'       => $result['error'] ?? 'Unknown error',
                            'status_code' => $result['statusCode'] ?? null,
                        ]);
                    }
                }

                unset($batchResults, $batchUrls);

                if ( $batchIndex < $totalBatches - 1 ) {
                    usleep(500000);
                }

                if ( $batchNumber % self::GC_COLLECT_INTERVAL === 0 ) {
                    gc_collect_cycles();
                }
            }
        }

        $productLinks = array_values(array_unique($productLinks));

        Log::info('Category link collection completed', [
            'category_url'       => $categoryUrl,
            'total_pages'        => $totalPages,
            'total_unique_links' => count($productLinks),
        ]);

        return $productLinks;
    }

    /**
     * Fetch HTML content from a URL with retry logic.
     */
    private function fetchPage (string $url) : ?string {
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
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_ENCODING       => 'gzip, deflate, br',
                    CURLOPT_TCP_KEEPALIVE  => 1,
                    CURLOPT_TCP_KEEPIDLE   => 300,
                    CURLOPT_TCP_KEEPINTVL  => 300,
                    CURLOPT_HTTPHEADER     => [
                        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
                        'Accept-Language: en-US,en;q=0.9,nl;q=0.8',
                        'Accept-Encoding: gzip, deflate, br, zstd',
                        'Referer: ' . self::BASE_URL . '/',
                        'Origin: ' . self::BASE_URL . '/',
                        'Connection: keep-alive',
                        'Upgrade-Insecure-Requests: 1',
                        'Sec-Fetch-Dest: document',
                        'Sec-Fetch-Mode: navigate',
                        'Sec-Fetch-Site: none',
                        'Sec-Fetch-User: ?1',
                        'Cache-Control: max-age=0',
                    ],
                ]);

                $html     = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error    = curl_error($ch);
                curl_close($ch);

                if ( $error ) {
                    throw new RuntimeException("cURL error: {$error}");
                }

                if ( $httpCode >= 200 && $httpCode < 300 ) {
                    if ( $attempt > 1 ) {
                        Log::info('Successfully fetched page after retry', ['url' => $url, 'attempts' => $attempt]);
                    }

                    return $html;
                }

                if ( $httpCode === 429 || $httpCode === 503 ) {
                    $waitTime = pow(2, $attempt) + 5;
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
                    $waitTime = pow(2, $attempt);
                    Log::warning('Non-200 HTTP response, retrying', [
                        'url'          => $url,
                        'status_code'  => $httpCode,
                        'attempt'      => $attempt,
                        'wait_seconds' => $waitTime,
                    ]);
                    sleep((int) $waitTime);
                    continue;
                }

                Log::error('Failed to fetch page after all retries', [
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
                    $waitTime = pow(2, $attempt);
                    Log::warning('Retryable error on page fetch, retrying', [
                        'url'             => $url,
                        'error'           => $errorMessage,
                        'attempt'         => $attempt,
                        'wait_seconds'    => $waitTime,
                        'exception_class' => get_class($e),
                    ]);
                    sleep((int) $waitTime);
                    continue;
                }

                Log::error('Error fetching page', [
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
     * Save the first successfully fetched HTML page for debugging.
     */
    private function saveFirstPageHtml (string $html, string $categoryUrl) : void {
        try {
            $debugDir = storage_path('logs/tommyhilfiger');
            if ( !file_exists($debugDir) ) {
                mkdir($debugDir, 0755, true);
            }

            $timestamp = now()->format('Y-m-d_H-i-s');
            $filename  = "first-page-html-{$timestamp}.html";
            $filePath  = $debugDir . '/' . $filename;

            file_put_contents($filePath, $html);

            Log::info('First page HTML saved', [
                'category_url' => $categoryUrl,
                'file_path'    => $filePath,
                'file_size'    => filesize($filePath),
            ]);
        } catch ( Exception $e ) {
            Log::warning('Failed to save first page HTML', [
                'category_url' => $categoryUrl,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Parse pagination information from HTML.
     */
    private function parsePaginationInfo (string $html) : ?array {
        try {
            $crawler           = new Crawler($html);
            $paginationElement = $crawler->filter(self::PAGINATION_ITEM_COUNT_SELECTOR)->first();

            if ( $paginationElement->count() === 0 ) {
                Log::debug('Pagination element not found', ['selector' => self::PAGINATION_ITEM_COUNT_SELECTOR]);
                unset($crawler);

                return null;
            }

            $text = $paginationElement->text();
            unset($crawler);

            if ( preg_match('/You\'ve\s+viewed\s+(\d+)\s+of\s+(\d+)\s+items/i', $text, $matches) ) {
                $itemsPerPage = (int) $matches[1];
                $totalItems   = (int) $matches[2];

                if ( $itemsPerPage <= 0 || $totalItems <= 0 ) {
                    Log::warning('Invalid pagination numbers', [
                        'items_per_page' => $itemsPerPage,
                        'total_items'    => $totalItems,
                        'text'           => $text,
                    ]);

                    return null;
                }

                $totalPages = (int) ceil($totalItems / $itemsPerPage);

                return [
                    'itemsPerPage' => $itemsPerPage,
                    'totalItems'   => $totalItems,
                    'totalPages'   => $totalPages,
                ];
            }

            Log::warning('Could not parse pagination text', ['text' => $text]);

            return null;

        } catch ( Exception $e ) {
            Log::error('Error parsing pagination info', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Extract product detail page URLs from category page HTML.
     */
    private function extractProductLinks (string $html, string $baseUrl) : array {
        $links = [];

        try {
            $crawler      = new Crawler($html);
            $productItems = $crawler->filter(self::PRODUCT_GRID_ITEM_SELECTOR);

            foreach ( $productItems as $item ) {
                $itemCrawler = new Crawler($item);
                $linkElement = $itemCrawler->filter(self::PRODUCT_LINK_SELECTOR)->first();

                if ( $linkElement->count() > 0 ) {
                    $href = $linkElement->attr('href');

                    if ( $href ) {
                        $absoluteUrl = $this->normalizeUrl($href, $baseUrl);
                        if ( $absoluteUrl ) {
                            $links[] = $absoluteUrl;
                        }
                    }
                }
                unset($itemCrawler);
            }

            unset($crawler);

        } catch ( Exception $e ) {
            Log::error('Error extracting product links', ['error' => $e->getMessage()]);
        }

        return $links;
    }

    /**
     * Normalize a URL to an absolute URL.
     */
    private function normalizeUrl (string $url, string $baseUrl) : ?string {
        if ( preg_match('/^https?:\/\//', $url) ) {
            return $url;
        }

        $baseUrl = rtrim($baseUrl, '/');
        $url     = ltrim($url, '/');

        return $baseUrl . '/' . $url;
    }

    /**
     * Build a paginated category URL.
     */
    private function buildPageUrl (string $basePath, int $page, array $existingQueryParams = []) : string {
        $queryParams         = $existingQueryParams;
        $queryParams['page'] = $page;

        $queryString = http_build_query($queryParams);
        $baseUrl     = rtrim(self::BASE_URL, '/');
        $basePath    = '/' . ltrim($basePath, '/');

        return $baseUrl . $basePath . '?' . $queryString;
    }

    /**
     * Fetch multiple category pages concurrently using multi-cURL with optimized memory handling.
     */
    private function fetchPageBatch (array $urls) : array {
        $results     = [];
        $multiHandle = curl_multi_init();
        $handles     = [];

        foreach ( $urls as $page => $url ) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::HTTP_CONNECT_TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_ENCODING       => 'gzip, deflate, br',
                CURLOPT_TCP_KEEPALIVE  => 1,
                CURLOPT_TCP_KEEPIDLE   => 300,
                CURLOPT_TCP_KEEPINTVL  => 300,
                CURLOPT_HTTPHEADER     => [
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
                    'Accept-Language: en-US,en;q=0.9,nl;q=0.8',
                    'Accept-Encoding: gzip, deflate, br, zstd',
                    'Referer: ' . self::BASE_URL . '/',
                    'Origin: ' . self::BASE_URL . '/',
                    'Connection: keep-alive',
                    'Upgrade-Insecure-Requests: 1',
                    'Sec-Fetch-Dest: document',
                    'Sec-Fetch-Mode: navigate',
                    'Sec-Fetch-Site: none',
                    'Sec-Fetch-User: ?1',
                    'Cache-Control: max-age=0',
                ],
            ]);

            curl_multi_add_handle($multiHandle, $ch);
            $handles[$page] = $ch;
            $results[$page] = [
                'success'    => false,
                'html'       => null,
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

        foreach ( $handles as $page => $ch ) {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);

            if ( $error ) {
                $results[$page]['error'] = $error;
            } elseif ( $httpCode >= 200 && $httpCode < 300 ) {
                $html           = curl_multi_getcontent($ch);
                $results[$page] = [
                    'success'    => true,
                    'html'       => $html,
                    'error'      => null,
                    'statusCode' => $httpCode,
                ];
            } else {
                $results[$page]['statusCode'] = $httpCode;
                $results[$page]['error']      = "HTTP {$httpCode}";
            }

            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }

        curl_multi_close($multiHandle);
        unset($handles, $multiHandle);

        return $results;
    }
}

