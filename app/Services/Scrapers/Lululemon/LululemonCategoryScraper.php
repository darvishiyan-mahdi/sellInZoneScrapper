<?php

declare(strict_types=1);

namespace App\Services\Scrapers\Lululemon;

use App\Services\Utils\NodeRenderService;
use Exception;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;

class LululemonCategoryScraper {
    private const BASE_URL                     = 'https://www.eu.lululemon.com';
    private const CATEGORY_PAGE_URL            = 'https://www.eu.lululemon.com/en-lu/c';
    private const PRODUCT_COUNT_SELECTOR       = '.js-cdp-current-count';
    private const PRODUCT_LINK_SELECTOR        = 'a.link.search-results-product-name';
    private const CATEGORY_LINK_SELECTORS     = [
        'a.category-tile__link',
        '.category-nav a',
        '.gender-tile a',
        '.mega-menu a',
        'a[href*="/c/"]',
    ];
    private const DEFAULT_PAGE_SIZE            = 44;
    private const MAX_PAGE_SIZE                = 200; // Maximum items per page
    private const HTTP_TIMEOUT                 = 300; // 5 minutes timeout for Puppeteer
    private const DEFAULT_CATEGORY_CONCURRENCY = 1; // Start with 1 for Puppeteer (can be increased later)
    private const GC_COLLECT_INTERVAL          = 10;
    private const CATEGORY_LINKS_CACHE_PATH   = 'storage/app/lululemon/category-links.json';
    private const PRODUCT_LINKS_CACHE_PATH    = 'storage/app/lululemon/product-links.json';
    private static bool $firstHtmlSaved = false;

    private NodeRenderService $nodeRenderService;

    public function __construct(?NodeRenderService $nodeRenderService = null) {
        $this->nodeRenderService = $nodeRenderService ?? new NodeRenderService();
    }

    /**
     * Get all category links from the main category page.
     * 
     * @param bool $useCache If true, load from cache if available
     * @return array Array of absolute category URLs
     */
    public function getAllCategoryLinks(bool $useCache = true): array {
        // Try to load from cache first
        if ($useCache) {
            $cachedLinks = $this->loadCategoryLinksFromCache();
            if ($cachedLinks !== null) {
                Log::info('Loaded category links from cache', [
                    'count' => count($cachedLinks),
                ]);
                return $cachedLinks;
            }
        }

        Log::info('Fetching category links from website', [
            'url' => self::CATEGORY_PAGE_URL,
        ]);

        // Fetch the category page with Puppeteer
        $html = $this->fetchPage(self::CATEGORY_PAGE_URL);
        if ($html === null) {
            Log::error('Failed to fetch category page', [
                'url' => self::CATEGORY_PAGE_URL,
            ]);
            return [];
        }

        // Extract category links
        $categoryLinks = $this->extractCategoryLinks($html);
        
        Log::info('Extracted category links', [
            'total_links' => count($categoryLinks),
        ]);

        // Save to cache
        $this->saveCategoryLinksToCache($categoryLinks);

        return $categoryLinks;
    }

    /**
     * Extract category links from HTML.
     */
    private function extractCategoryLinks(string $html): array {
        $links = [];

        try {
            $crawler = new Crawler($html);

            // Try each selector to find category links
            foreach (self::CATEGORY_LINK_SELECTORS as $selector) {
                $elements = $crawler->filter($selector);
                
                foreach ($elements as $element) {
                    $linkCrawler = new Crawler($element);
                    $href = $linkCrawler->attr('href');

                    if ($href) {
                        $absoluteUrl = $this->normalizeUrl($href, self::BASE_URL);
                        
                        // Only include URLs that look like category pages
                        if ($absoluteUrl && $this->isCategoryUrl($absoluteUrl)) {
                            $links[] = $absoluteUrl;
                        }
                    }
                    unset($linkCrawler);
                }
            }

            unset($crawler);
        } catch (Exception $e) {
            Log::error('Error extracting category links', [
                'error' => $e->getMessage(),
            ]);
        }

        // Remove duplicates and return
        return array_values(array_unique($links));
    }

    /**
     * Check if URL is a category page URL.
     */
    private function isCategoryUrl(string $url): bool {
        // Category URLs typically contain /c/ in the path
        // Exclude product pages (/p/) and other non-category pages
        return strpos($url, '/c/') !== false && strpos($url, '/p/') === false;
    }

    /**
     * Save category links to cache file.
     */
    private function saveCategoryLinksToCache(array $links): void {
        try {
            $cachePath = base_path(self::CATEGORY_LINKS_CACHE_PATH);
            $cacheDir = dirname($cachePath);
            
            if (!file_exists($cacheDir)) {
                mkdir($cacheDir, 0755, true);
            }

            $data = [
                'fetched_at' => now()->toIso8601String(),
                'count' => count($links),
                'links' => $links,
            ];

            file_put_contents($cachePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            Log::info('Saved category links to cache', [
                'path' => $cachePath,
                'count' => count($links),
            ]);
        } catch (Exception $e) {
            Log::warning('Failed to save category links to cache', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Load category links from cache file.
     */
    private function loadCategoryLinksFromCache(): ?array {
        try {
            $cachePath = base_path(self::CATEGORY_LINKS_CACHE_PATH);
            
            if (!file_exists($cachePath)) {
                return null;
            }

            $content = file_get_contents($cachePath);
            $data = json_decode($content, true);

            if (!isset($data['links']) || !is_array($data['links'])) {
                return null;
            }

            return $data['links'];
        } catch (Exception $e) {
            Log::warning('Failed to load category links from cache', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Load product links from cache for a category.
     */
    public function loadProductLinksFromCache(string $categoryUrl): ?array {
        try {
            $cachePath = base_path(self::PRODUCT_LINKS_CACHE_PATH);
            
            if (!file_exists($cachePath)) {
                return null;
            }

            $content = file_get_contents($cachePath);
            $data = json_decode($content, true);

            if (!isset($data['categories'][$categoryUrl]['links']) || !is_array($data['categories'][$categoryUrl]['links'])) {
                return null;
            }

            Log::info('Loaded product links from cache', [
                'category_url' => $categoryUrl,
                'count' => count($data['categories'][$categoryUrl]['links']),
            ]);

            return $data['categories'][$categoryUrl]['links'];
        } catch (Exception $e) {
            Log::warning('Failed to load product links from cache', [
                'error' => $e->getMessage(),
                'category_url' => $categoryUrl,
            ]);
            return null;
        }
    }

    /**
     * Save product links for a single category to cache.
     */
    private function saveProductLinksToCache(string $categoryUrl, array $productLinks): void {
        try {
            $cachePath = base_path(self::PRODUCT_LINKS_CACHE_PATH);
            $cacheDir = dirname($cachePath);
            
            if (!file_exists($cacheDir)) {
                mkdir($cacheDir, 0755, true);
            }

            // Load existing cache or create new structure
            $existingData = [];
            if (file_exists($cachePath)) {
                $content = file_get_contents($cachePath);
                $existingData = json_decode($content, true) ?? [];
            }

            // Update or add category data
            if (!isset($existingData['categories']) || !is_array($existingData['categories'])) {
                $existingData['categories'] = [];
            }

            $existingData['categories'][$categoryUrl] = [
                'fetched_at' => now()->toIso8601String(),
                'count' => count($productLinks),
                'links' => $productLinks,
            ];

            // Update totals
            $allLinks = [];
            foreach ($existingData['categories'] as $catData) {
                if (isset($catData['links']) && is_array($catData['links'])) {
                    $allLinks = array_merge($allLinks, $catData['links']);
                }
            }
            $existingData['total_products'] = count(array_unique($allLinks));
            $existingData['last_updated'] = now()->toIso8601String();

            file_put_contents($cachePath, json_encode($existingData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            Log::info('Saved product links to cache', [
                'path' => $cachePath,
                'category_url' => $categoryUrl,
                'product_count' => count($productLinks),
            ]);
        } catch (Exception $e) {
            Log::warning('Failed to save product links to cache', [
                'error' => $e->getMessage(),
                'category_url' => $categoryUrl,
            ]);
        }
    }

    /**
     * Save all product links from all categories to cache.
     */
    private function saveAllProductLinksToCache(array $allProductLinks): void {
        try {
            $cachePath = base_path(self::PRODUCT_LINKS_CACHE_PATH);
            $cacheDir = dirname($cachePath);
            
            if (!file_exists($cacheDir)) {
                mkdir($cacheDir, 0755, true);
            }

            // Collect all unique product links
            $allLinks = [];
            foreach ($allProductLinks as $productLinks) {
                $allLinks = array_merge($allLinks, $productLinks);
            }
            $uniqueLinks = array_values(array_unique($allLinks));

            $data = [
                'fetched_at' => now()->toIso8601String(),
                'total_categories' => count($allProductLinks),
                'total_products' => count($uniqueLinks),
                'categories' => [],
                'all_products' => $uniqueLinks,
            ];

            // Add per-category data
            foreach ($allProductLinks as $categoryUrl => $productLinks) {
                $data['categories'][$categoryUrl] = [
                    'count' => count($productLinks),
                    'links' => $productLinks,
                ];
            }

            file_put_contents($cachePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            Log::info('Saved all product links to cache', [
                'path' => $cachePath,
                'total_categories' => count($allProductLinks),
                'total_products' => count($uniqueLinks),
            ]);
        } catch (Exception $e) {
            Log::warning('Failed to save all product links to cache', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Collect product links from all categories.
     * 
     * @param bool $useCache If true, use cached category links if available
     * @return array Associative array with category URLs as keys and product link arrays as values
     */
    public function collectProductLinksFromAllCategories(bool $useCache = true): array {
        Log::info('Starting product link collection from all categories');
        
        // Get all category links
        $categoryLinks = $this->getAllCategoryLinks($useCache);
        
        if (empty($categoryLinks)) {
            Log::warning('No category links found');
            return [];
        }
        
        Log::info('Processing categories', [
            'total_categories' => count($categoryLinks),
        ]);
        
        $allProductLinks = [];
        $processedCount = 0;
        $totalProducts = 0;
        
        foreach ($categoryLinks as $categoryUrl) {
            $processedCount++;
            
            Log::info('Processing category', [
                'category_number' => $processedCount,
                'total_categories' => count($categoryLinks),
                'category_url' => $categoryUrl,
            ]);
            
            try {
                $productLinks = $this->collectProductLinks($categoryUrl);
                $allProductLinks[$categoryUrl] = $productLinks;
                $totalProducts += count($productLinks);
                
                Log::info('Category processed successfully', [
                    'category_url' => $categoryUrl,
                    'products_found' => count($productLinks),
                    'total_products_so_far' => $totalProducts,
                ]);
            } catch (Exception $e) {
                Log::error('Failed to process category', [
                    'category_url' => $categoryUrl,
                    'error' => $e->getMessage(),
                ]);
                $allProductLinks[$categoryUrl] = [];
            }
        }
        
        Log::info('Completed product link collection from all categories', [
            'total_categories' => count($categoryLinks),
            'successful_categories' => count(array_filter($allProductLinks, fn($links) => !empty($links))),
            'total_products' => $totalProducts,
        ]);
        
        // Save all product links to cache
        $this->saveAllProductLinksToCache($allProductLinks);
        
        return $allProductLinks;
    }

    /**
     * Collect all product detail page URLs from a Lululemon category page.
     *
     * @param string $categoryUrl The category URL (e.g., https://www.eu.lululemon.com/en-lu/c/mens/collections/we-made-too-much?sz=44)
     *
     * @return array Array of unique absolute product URLs
     */
    public function collectProductLinks(string $categoryUrl): array {
        Log::info('Starting Lululemon category link collection', [
            'category_url' => $categoryUrl,
        ]);

        $productLinks = [];
        $parsedUrl    = parse_url($categoryUrl);
        $basePath     = $parsedUrl['path'] ?? '';
        $queryParams  = [];
        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $queryParams);
        }

        // Fetch first page with Puppeteer
        $firstPageHtml = $this->fetchPage($categoryUrl);
        if ($firstPageHtml === null) {
            Log::error('Failed to fetch first page', ['category_url' => $categoryUrl]);
            return [];
        }

        // Save first page HTML for debugging
        if (!self::$firstHtmlSaved) {
            $this->saveFirstPageHtml($firstPageHtml, $categoryUrl);
            self::$firstHtmlSaved = true;
        }

        // Parse product count from js-cdp-current-count element
        $productCountInfo = $this->parseProductCount($firstPageHtml);
        if ($productCountInfo === null) {
            Log::warning('Could not parse product count, extracting links from first page only', [
                'category_url' => $categoryUrl,
            ]);

            $firstPageLinks = $this->extractProductLinks($firstPageHtml, self::BASE_URL);
            $uniqueLinks = array_values(array_unique($firstPageLinks));
            
            Log::info('Extracted links from first page (no count available)', [
                'category_url' => $categoryUrl,
                'links_found' => count($uniqueLinks),
            ]);
            
            // Save product links to cache
            $this->saveProductLinksToCache($categoryUrl, $uniqueLinks);
            
            return $uniqueLinks;
        }

        $currentCount = $productCountInfo['current'];
        $totalCount   = $productCountInfo['total'];

        Log::info('Product count parsed', [
            'current_count' => $currentCount,
            'total_count'   => $totalCount,
        ]);

        // Strategy: Use the total count to build a URL with sz=totalCount, then scroll and collect all links
        Log::info('Building URL with total count and fetching all products', [
            'total_count' => $totalCount,
            'current_count' => $currentCount,
        ]);

        // Build URL with sz parameter set to the total count
        $queryParams['sz'] = $totalCount;
        $allProductsUrl    = $this->buildPageUrl($basePath, $queryParams);

        Log::info('Fetching page with all products', [
            'url' => $allProductsUrl,
            'sz' => $totalCount,
        ]);

        // Fetch the page with all products (Puppeteer will scroll to load lazy-loaded content)
        $allProductsHtml = $this->fetchPageWithScrolling($allProductsUrl);
        
        if ($allProductsHtml === null) {
            Log::error('Failed to fetch all products page', [
                'url' => $allProductsUrl,
            ]);
            
            // Fallback: try with first page links
            $firstPageLinks = $this->extractProductLinks($firstPageHtml, self::BASE_URL);
            $uniqueLinks = array_values(array_unique($firstPageLinks));
            
            // Save product links to cache
            $this->saveProductLinksToCache($categoryUrl, $uniqueLinks);
            
            return $uniqueLinks;
        }

        // Extract all product links from the fully loaded page
        $allProductsLinks = $this->extractProductLinks($allProductsHtml, self::BASE_URL);
        
        Log::info('Extracted product links from full page', [
            'total_links' => count($allProductsLinks),
            'expected' => $totalCount,
            'url' => $allProductsUrl,
            'extraction_rate' => $totalCount > 0 ? round((count($allProductsLinks) / $totalCount) * 100, 2) . '%' : 'N/A',
        ]);
        
        $productLinks = array_values(array_unique($allProductsLinks));
        
        Log::info('Category link collection completed', [
            'category_url'       => $categoryUrl,
            'total_expected'     => $totalCount,
            'total_unique_links' => count($productLinks),
            'extraction_rate'    => $totalCount > 0 ? round((count($productLinks) / $totalCount) * 100, 2) . '%' : 'N/A',
        ]);

        // Save product links to cache
        $this->saveProductLinksToCache($categoryUrl, $productLinks);

        return $productLinks;
    }

    /**
     * Fetch HTML content from a URL using Puppeteer via NodeRenderService.
     */
    private function fetchPage(string $url): ?string {
        try {
            $html = $this->nodeRenderService->getRenderedHtml($url, self::PRODUCT_COUNT_SELECTOR, self::HTTP_TIMEOUT);

            return $html;
        } catch (Exception $e) {
            Log::error('Error fetching page with Puppeteer', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Fetch HTML content with enhanced scrolling for lazy-loaded products.
     * This method ensures all products are loaded by scrolling the page.
     */
    private function fetchPageWithScrolling(string $url): ?string {
        try {
            // For pages with all products, we don't need to wait for the count selector
            // Instead, we'll scroll and wait for products to load
            // Pass null as waitSelector to skip waiting for count, but Puppeteer will still scroll
            $html = $this->nodeRenderService->getRenderedHtml($url, null, self::HTTP_TIMEOUT);

            return $html;
        } catch (Exception $e) {
            Log::error('Error fetching page with scrolling', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Save the first successfully fetched HTML page for debugging.
     */
    private function saveFirstPageHtml(string $html, string $categoryUrl): void {
        try {
            $debugDir = storage_path('logs/lululemon');
            if (!file_exists($debugDir)) {
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
        } catch (Exception $e) {
            Log::warning('Failed to save first page HTML', [
                'category_url' => $categoryUrl,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Parse product count information from the js-cdp-current-count element.
     * Expected format: "44 of 306" or "44 of 306 items"
     *
     * @return array|null Array with 'current' and 'total' keys, or null if parsing fails
     */
    private function parseProductCount(string $html): ?array {
        try {
            $crawler      = new Crawler($html);
            $countElement = $crawler->filter(self::PRODUCT_COUNT_SELECTOR)->first();

            if ($countElement->count() === 0) {
                Log::debug('Product count element not found', [
                    'selector' => self::PRODUCT_COUNT_SELECTOR,
                ]);
                unset($crawler);

                return null;
            }

            $text = trim($countElement->text());
            unset($crawler);

            // Parse pattern like "44 of 306" or "44 of 306 items"
            if (preg_match('/(\d+)\s+of\s+(\d+)/i', $text, $matches)) {
                $current = (int)$matches[1];
                $total   = (int)$matches[2];

                if ($current <= 0 || $total <= 0 || $current > $total) {
                    Log::warning('Invalid product count numbers', [
                        'current' => $current,
                        'total'   => $total,
                        'text'    => $text,
                    ]);

                    return null;
                }

                return [
                    'current' => $current,
                    'total'   => $total,
                ];
            }

            Log::warning('Could not parse product count text', ['text' => $text]);

            return null;
        } catch (Exception $e) {
            Log::error('Error parsing product count', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Extract product detail page URLs from category page HTML.
     */
    private function extractProductLinks(string $html, string $baseUrl): array {
        $links = [];

        try {
            $crawler      = new Crawler($html);
            
            // Try the specific selector first
            $productLinks = $crawler->filter(self::PRODUCT_LINK_SELECTOR);
            
            // If no links found with specific selector, try fallback selector
            if ($productLinks->count() === 0) {
                Log::debug('No links found with specific selector, trying fallback', [
                    'selector' => self::PRODUCT_LINK_SELECTOR,
                ]);
                $productLinks = $crawler->filter('a[href*="/p/"]');
            }

            foreach ($productLinks as $linkElement) {
                $linkCrawler = new Crawler($linkElement);
                $href        = $linkCrawler->attr('href');

                if ($href) {
                    $absoluteUrl = $this->normalizeUrl($href, $baseUrl);
                    if ($absoluteUrl && $this->isProductUrl($absoluteUrl)) {
                        $links[] = $absoluteUrl;
                    }
                }
                unset($linkCrawler);
            }

            unset($crawler);
        } catch (Exception $e) {
            Log::error('Error extracting product links', [
                'error' => $e->getMessage(),
                'selector' => self::PRODUCT_LINK_SELECTOR,
            ]);
        }

        return $links;
    }

    /**
     * Check if URL is a product detail page URL.
     */
    private function isProductUrl(string $url): bool {
        // Lululemon product URLs typically contain /p/ in the path
        return strpos($url, '/p/') !== false;
    }

    /**
     * Normalize a URL to an absolute URL.
     */
    private function normalizeUrl(string $url, string $baseUrl): ?string {
        if (preg_match('/^https?:\/\//', $url)) {
            return $url;
        }

        $baseUrl = rtrim($baseUrl, '/');
        $url     = ltrim($url, '/');

        return $baseUrl . '/' . $url;
    }

    /**
     * Build a category page URL with query parameters.
     */
    private function buildPageUrl(string $basePath, array $queryParams = []): string {
        $queryString = http_build_query($queryParams);
        $basePath    = rtrim($basePath, '/');

        return self::BASE_URL . $basePath . ($queryString ? '?' . $queryString : '');
    }
}
