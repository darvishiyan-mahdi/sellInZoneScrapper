<?php

declare(strict_types=1);

namespace App\Services\Scrapers\Nike;

use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductMedia;
use App\Models\Website;
use App\Services\AI\Gemini\GeminiFunctions;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Process\Process;

class NikeProductDetailScraper {
    private const BASE_URL                  = 'https://www.nike.com';
    private const DEFAULT_CONCURRENCY       = 20;
    private const DEFAULT_BATCH_SIZE        = 200;
    private const HTTP_TIMEOUT              = 60;
    private const HTTP_CONNECT_TIMEOUT      = 30;
    private const GC_COLLECT_INTERVAL       = 5;
    private const CURL_MULTI_SELECT_TIMEOUT = 0.1;
    private const MAX_RETRIES               = 5;
    private const RETRY_DELAY_BASE          = 2;
    private const COLOR_PAGE_CONCURRENCY    = 8;

    // Selectors for Nike product pages
    private const SELECTOR_COLOR_LINK        = 'a[data-testid^="colorway-link-"]';
    private const SELECTOR_PRICE_CONTAINER  = 'div#price-container';
    private const SELECTOR_HERO_IMAGE       = 'div#hero-image img[data-testid="HeroImg"]';
    private const SELECTOR_SIZE_ITEM         = 'div.css-1jas9ft.nds-grid-item';
    private const SELECTOR_SIZE_DISABLED     = 'div.css-1jas9ft.nds-grid-item.disabled';
    private const SELECTOR_DESCRIPTION_CONTAINER = 'div#product-description-container';
    private const SELECTOR_DESCRIPTION_P        = 'div#product-description-container p';
    private const SELECTOR_DESCRIPTION_LI        = 'div#product-description-container li';

    private Website $website;

    public function __construct (Website $website) {
        $this->website = $website;
    }

    /**
     * Scrape products from URLs using multi-cURL batch fetching with optimized memory management.
     *
     * @param array    $productUrls Array of absolute product URLs
     * @param int      $concurrency Number of concurrent requests
     * @param int      $batchSize   Number of URLs to process per batch
     * @param int|null $maxProducts Maximum number of products to scrape (null = no limit)
     * @param float    $batchSleep  Sleep time in seconds between batches
     *
     * @return void
     */
    public function scrapeProducts (
        array $productUrls, int $concurrency = self::DEFAULT_CONCURRENCY, int $batchSize = self::DEFAULT_BATCH_SIZE, ?int $maxProducts = null, float $batchSleep = 0.2
    ) : void {
        $productUrls = array_values(array_unique($productUrls));

        if ( $maxProducts !== null && $maxProducts > 0 ) {
            $productUrls = array_slice($productUrls, 0, $maxProducts);
        }

        $totalUrls = count($productUrls);

        Log::info('Starting Nike product detail scraping', [
            'total_urls'   => $totalUrls,
            'concurrency'  => $concurrency,
            'batch_size'   => $batchSize,
            'max_products' => $maxProducts,
        ]);

        $urlBatches   = array_chunk($productUrls, $batchSize);
        $totalBatches = count($urlBatches);
        $processed    = 0;
        $successful   = 0;
        $failed       = 0;
        $startTime    = microtime(true);

        foreach ( $urlBatches as $batchIndex => $urlBatch ) {
            $batchNumber    = $batchIndex + 1;
            $batchStartTime = microtime(true);

            Log::info("Processing URL batch {$batchNumber} of {$totalBatches}", [
                'batch_size'       => count($urlBatch),
                'processed_so_far' => $processed,
            ]);

            $concurrencyBatches    = array_chunk($urlBatch, $concurrency);
            $concurrencyBatchCount = count($concurrencyBatches);

            foreach ( $concurrencyBatches as $concurrencyBatchIndex => $concurrencyBatch ) {
                $this->fetchAndProcessBatch($concurrencyBatch, $processed, $successful, $failed);

                if ( $concurrencyBatchIndex < $concurrencyBatchCount - 1 ) {
                    usleep(100000);
                }
            }

            $batchDuration = microtime(true) - $batchStartTime;
            $elapsed       = microtime(true) - $startTime;
            $rate          = $processed > 0 ? round($processed / $elapsed, 2) : 0;

            Log::info("URL batch {$batchNumber} completed", [
                'processed'      => $processed,
                'successful'     => $successful,
                'failed'         => $failed,
                'progress'       => round(($processed / $totalUrls) * 100, 2) . '%',
                'batch_duration' => round($batchDuration, 2) . 's',
                'overall_rate'   => $rate . ' products/s',
            ]);

            if ( $batchIndex < $totalBatches - 1 && $batchSleep > 0 ) {
                usleep((int) ($batchSleep * 1000000));
            }

            if ( $batchNumber % self::GC_COLLECT_INTERVAL === 0 ) {
                gc_collect_cycles();
            }

            unset($urlBatch, $concurrencyBatches);
        }

        $totalDuration = microtime(true) - $startTime;
        Log::info('Nike product detail scraping completed', [
            'total_processed' => $processed,
            'successful'      => $successful,
            'failed'          => $failed,
            'total_duration'  => round($totalDuration, 2) . 's',
            'average_rate'    => $processed > 0 ? round($processed / $totalDuration, 2) . ' products/s' : '0 products/s',
        ]);
    }

    /**
     * Fetch a batch of URLs using Http::request and process them immediately to minimize memory usage.
     */
    private function fetchAndProcessBatch (array $urls, int &$processed, int &$successful, int &$failed) : void {
        foreach ( $urls as $url ) {
            $processed++;

            try {
                // Use Http::request to fetch product page
                $response = Http::timeout(self::HTTP_TIMEOUT)
                    ->connectTimeout(self::HTTP_CONNECT_TIMEOUT)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                        'Accept-Language' => 'en-US,en;q=0.9',
                        'Accept-Encoding' => 'gzip, deflate, br, zstd',
                        'Referer' => self::BASE_URL . '/',
                        'Origin' => self::BASE_URL,
                        'Connection' => 'keep-alive',
                        'Upgrade-Insecure-Requests' => '1',
                        'Sec-Fetch-Dest' => 'document',
                        'Sec-Fetch-Mode' => 'navigate',
                        'Sec-Fetch-Site' => 'none',
                        'Sec-Fetch-User' => '?1',
                        'Cache-Control' => 'max-age=0',
                    ])
                    ->get($url);

                if ( !$response->successful() ) {
                    $failed++;
                    Log::warning('Failed to fetch product page', [
                        'url'         => $url,
                        'status_code' => $response->status(),
                    ]);
                    continue;
                }

                $html = $response->body();
                $effectiveUri = $response->effectiveUri();
                $finalUrl = $effectiveUri ? (string) $effectiveUri : $url;

                // Save HTML to file for debugging
                $this->saveProductHtml($url, $html);

                // Parse and save product
                $this->parseAndSaveProduct($url, $html, $finalUrl);
                $successful++;

            } catch ( Exception $e ) {
                $failed++;
                Log::error('Error fetching/processing product', [
                    'url'   => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Save product HTML to file for debugging.
     */
    private function saveProductHtml (string $url, string $html) : void {
        try {
            $logDir = storage_path('logs/nike/products');
            if ( !file_exists($logDir) ) {
                mkdir($logDir, 0755, true);
            }

            $slug = $this->extractSlug($url);
            $timestamp = now()->format('Y-m-d_H-i-s');
            $filename = "product-{$slug}-{$timestamp}.html";
            $filePath = $logDir . '/' . $filename;

            file_put_contents($filePath, $html);

            Log::debug('Saved product HTML', [
                'url' => $url,
                'file_path' => $filePath,
                'file_size' => filesize($filePath),
            ]);
        } catch ( Exception $e ) {
            Log::warning('Failed to save product HTML', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Parse HTML and save product to database with optimized memory usage.
     */
    private function parseAndSaveProduct (string $url, string $html, string $finalUrl) : void {
        $crawler = new Crawler($html);

        $slug         = $this->extractSlug($finalUrl);
        $title        = $this->extractTitle($crawler);
        $description  = $this->extractDescription($crawler);
        $externalId   = $this->extractExternalId($url, $finalUrl);
        
        // Extract price from main page first - use this for all variations
        $basePriceData = $this->extractPrice($crawler);
        
        // Extract color links
        $colorLinks = $this->extractColorLinks($crawler, $finalUrl);
        
        // Build variations matrix by processing each color link
        $variationsMatrix = [];
        $allImages = [];

        if ( !empty($colorLinks) ) {
            Log::info('Processing color variants', [
                'url' => $url,
                'color_count' => count($colorLinks),
            ]);

            $colorBatches = array_chunk($colorLinks, self::COLOR_PAGE_CONCURRENCY);
            
            foreach ( $colorBatches as $colorBatch ) {
                $colorResults = $this->fetchColorPages($colorBatch);
                
                foreach ( $colorResults as $colorUrl => $result ) {
                    if ( !$result['success'] || empty($result['html']) ) {
                        Log::warning('Failed to fetch color page', [
                            'color_url' => $colorUrl,
                            'error' => $result['error'] ?? 'Unknown error',
                        ]);
                        continue;
                    }

                    $colorHtml = $result['html'];
                    $colorCrawler = new Crawler($colorHtml);
                    
                    // Extract data for this color variant (but use main page price)
                    $colorData = $this->extractColorVariantData($colorCrawler, $colorUrl, $colorHtml);
                    
                    if ( !empty($colorData) ) {
                        // Override price_data with main page price for all variations
                        $colorData['price_data'] = $basePriceData;
                        
                        // Use Playwright script to extract and download hero images for this variation
                        $playwrightImages = $this->extractHeroImagesWithPlaywright($url, $colorUrl);
                        if ( !empty($playwrightImages) ) {
                            // Merge Playwright images with extracted images (Playwright takes priority)
                            $colorData['images'] = array_merge($playwrightImages, $colorData['images']);
                            $colorData['images_playwright'] = $playwrightImages; // Keep track of Playwright images
                        }
                        
                        $variationsMatrix[] = $colorData;
                        
                        // Collect images
                        if ( !empty($colorData['images']) ) {
                            $allImages = array_merge($allImages, $colorData['images']);
                        }
                    }
                    
                    unset($colorCrawler, $colorHtml);
                }
                
                unset($colorResults);
            }
        } else {
            // No color links found, extract from main page
            Log::info('No color links found, extracting from main page', ['url' => $url]);
            
            $images = $this->extractImages($crawler);
            $sizes = $this->extractSizes($crawler);
            
            // Use Playwright script to extract hero images for main product
            $playwrightImages = $this->extractHeroImagesWithPlaywright($url, null);
            if ( !empty($playwrightImages) ) {
                // Merge Playwright images with extracted images (Playwright takes priority)
                $images = array_merge($playwrightImages, $images);
            }
            
            $allImages = $images;
            
            // Create a single variant entry
            if ( !empty($sizes) ) {
                $variationsMatrix[] = [
                    'colour_label' => 'Default',
                    'colour_slug' => 'default',
                    'pdp_url' => $finalUrl,
                    'price_data' => $basePriceData,
                    'images' => $images,
                    'variants' => $sizes,
                    'images_playwright' => $playwrightImages, // Keep track of Playwright images
                ];
            }
        }

        // Translate description to Persian using Gemini AI
        $descriptionTranslated = null;
        if ( !empty($description) ) {
            try {
                $geminiFunctions = new GeminiFunctions();
                $descriptionTranslated = $geminiFunctions->translateToPersian($description);
            } catch ( Exception $e ) {
                Log::warning('Failed to translate product description to Persian', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                    'description_preview' => substr($description, 0, 100) . '...',
                ]);
            }
        }

        unset($crawler);

        // Deduplicate images
        $allImages = $this->deduplicateImages($allImages);

        $rawData = [
            'url'            => $url,
            'final_url'      => $finalUrl,
            'title'          => $title,
            'description'    => $description,
            'external_id'    => $externalId,
            'price'          => $basePriceData['price'] ?? null,
            'currency'       => $basePriceData['currency'] ?? null,
            'original_price' => $basePriceData['original_price'] ?? null,
            'discount_percentage' => $basePriceData['discount_percentage'] ?? null,
            'images'         => $allImages,
        ];

        // Add variations matrix to raw_data
        if ( !empty($variationsMatrix) ) {
            $rawData['variations_matrix'] = $variationsMatrix;
        }

        unset($html);

        // Build meta array with translated description
        $meta = [
            'brand' => 'Nike',
        ];
        
        if ( $descriptionTranslated !== null ) {
            $meta['description_translated'] = $descriptionTranslated;
        }

        // Determine availability status
        $hasAvailableSizes = false;
        foreach ( $variationsMatrix as $colorVariant ) {
            foreach ( $colorVariant['variants'] ?? [] as $sizeVariant ) {
                if ( !empty($sizeVariant['stock_availability']) ) {
                    $hasAvailableSizes = true;
                    break 2;
                }
            }
        }
        
        $status = $hasAvailableSizes ? 'published' : 'out_of_stock';

        DB::transaction(function () use ($slug, $externalId, $title, $description, $basePriceData, $allImages, $rawData, $variationsMatrix, $meta, $status) {
            // Check if product already exists to preserve existing meta data
            $existingProduct = Product::where('website_id', $this->website->id)
                ->where('external_id', $externalId)
                ->first();
            
            // Merge with existing meta if product exists
            if ( $existingProduct && !empty($existingProduct->meta) && is_array($existingProduct->meta) ) {
                $meta = array_merge($existingProduct->meta, $meta);
            }
            
            $product = Product::updateOrCreate([
                    'website_id'  => $this->website->id,
                    'external_id' => $externalId,
                ], [
                    'title'          => $title,
                    'slug'           => $slug,
                    'description'    => $description,
                    'price'          => $basePriceData['price'] ?? null,
                    'currency'       => $basePriceData['currency'] ?? 'CAD',
                    'stock_quantity' => 1,
                    'status'         => $status,
                    'raw_data'       => $rawData,
                    'meta'           => $meta,
                ]);

            $product->media()->delete();
            $product->attributes()->delete();

            // Collect images from variations matrix instead of downloading separately
            // This prevents duplicate downloads since Playwright already saves them to variation folders
            if ( !empty($variationsMatrix) ) {
                $mediaData = [];
                $isFirst   = true;
                $seenUrls  = []; // Track URLs to avoid duplicates
                
                foreach ( $variationsMatrix as $colorVariant ) {
                    $colorImages = $colorVariant['images'] ?? [];
                    
                    foreach ( $colorImages as $image ) {
                        $imageUrl = $image['url'] ?? '';
                        $localPath = $image['local_path'] ?? null;
                        
                        // Skip if no URL or already seen
                        if ( empty($imageUrl) || isset($seenUrls[$imageUrl]) ) {
                            continue;
                        }
                        
                        $seenUrls[$imageUrl] = true;
                        
                        // Use local_path from Playwright if available (already includes images/nike/ prefix)
                        // (We don't want to download again to main folder)
                        if ( $localPath ) {
                            $mediaData[] = [
                                'product_id' => $product->id,
                                'type'       => 'image',
                                'source_url' => $localPath,
                                'local_path' => $localPath,
                                'alt_text'   => $image['alt'] ?? null,
                                'is_primary' => $isFirst,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                            $isFirst = false;
                        }
                    }
                }
                
                if ( !empty($mediaData) ) {
                    ProductMedia::insert($mediaData);
                }
                unset($mediaData, $seenUrls);
            } elseif ( !empty($allImages) ) {
                // Fallback: if no variations matrix, use allImages but only if they have local_path
                $mediaData = [];
                $isFirst   = true;
                
                foreach ( $allImages as $imageIndex => $image ) {
                    $imageUrl = $image['url'] ?? '';
                    $localPath = $image['local_path'] ?? null;
                    
                    if ( empty($imageUrl) ) {
                        continue;
                    }

                    // Only use images that already have local_path (from Playwright)
                    // local_path already includes images/nike/ prefix
                    // Don't download again to main folder
                    if ( $localPath ) {
                        $mediaData[] = [
                            'product_id' => $product->id,
                            'type'       => 'image',
                            'source_url' => $localPath,
                            'local_path' => $localPath,
                            'alt_text'   => $image['alt'] ?? null,
                            'is_primary' => $isFirst,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                        $isFirst = false;
                    }
                }
                
                if ( !empty($mediaData) ) {
                    ProductMedia::insert($mediaData);
                }
                unset($mediaData);
            }

            // Save variations matrix as attribute
            if ( !empty($variationsMatrix) ) {
                // Enrich variations matrix with local image paths
                $variationsMatrix = $this->enrichVariationsMatrixWithLocalImages($variationsMatrix, $product, $slug);

                $attributeData = [];

                // 1. variations_matrix - full JSON
                $attributeData[] = [
                    'product_id' => $product->id,
                    'name'       => 'variations_matrix',
                    'value'      => json_encode($variationsMatrix, JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                // 2. available_colours - JSON array of colours with stock
                $availableColours = collect($variationsMatrix)->filter(function ($colour) {
                        return collect($colour['variants'] ?? [])->contains(fn($v) => !empty($v['stock_availability']));
                    })->pluck('colour_label')->unique()->values()->all();

                if ( !empty($availableColours) ) {
                    $attributeData[] = [
                        'product_id' => $product->id,
                        'name'       => 'available_colours',
                        'value'      => json_encode($availableColours, JSON_UNESCAPED_UNICODE),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                // 3. available_sizes_by_colour - compact size availability map
                $sizeMap = [];
                foreach ( $variationsMatrix as $colourRow ) {
                    $colourLabel = $colourRow['colour_label'] ?? $colourRow['colour'] ?? null;
                    if ( !$colourLabel || empty($colourRow['variants']) ) {
                        continue;
                    }

                    foreach ( $colourRow['variants'] as $variant ) {
                        $size = $variant['size'] ?? null;
                        if ( !$size ) {
                            continue;
                        }
                        $inStock                      = !empty($variant['stock_availability']);
                        $sizeMap[$colourLabel][$size] = $inStock;
                    }
                }

                if ( !empty($sizeMap) ) {
                    $attributeData[] = [
                        'product_id' => $product->id,
                        'name'       => 'available_sizes_by_colour',
                        'value'      => json_encode($sizeMap, JSON_UNESCAPED_UNICODE),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if ( !empty($attributeData) ) {
                    ProductAttribute::insert($attributeData);
                    unset($attributeData);
                }
            }
        });

        unset($rawData, $allImages, $variationsMatrix);
    }

    /**
     * Extract slug from URL.
     */
    private function extractSlug (string $url) : string {
        $parsed   = parse_url($url);
        $path     = $parsed['path'] ?? '';
        $segments = array_filter(explode('/', $path));

        return end($segments) ?: 'unknown';
    }

    /**
     * Extract product title.
     */
    private function extractTitle (Crawler $crawler) : ?string {
        try {
            // Try multiple selectors for title
            $selectors = [
                'h1[data-testid="product-title"]',
                'h1.product-title',
                'h1',
            ];

            foreach ( $selectors as $selector ) {
                $element = $crawler->filter($selector)->first();
                if ( $element->count() > 0 ) {
                    return trim($element->text());
                }
            }
        } catch ( Exception $e ) {
            Log::debug('Error extracting title', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Extract product description.
     */
    private function extractDescription (Crawler $crawler) : ?string {
        try {
            $container = $crawler->filter(self::SELECTOR_DESCRIPTION_CONTAINER)->first();
            if ( $container->count() === 0 ) {
                return null;
            }

            $descriptionParts = [];

            // Extract paragraphs
            $paragraphs = $container->filter(self::SELECTOR_DESCRIPTION_P);
            foreach ( $paragraphs as $p ) {
                $text = trim((new Crawler($p))->text());
                if ( !empty($text) ) {
                    $descriptionParts[] = $text;
                }
            }

            // Extract list items
            $listItems = $container->filter(self::SELECTOR_DESCRIPTION_LI);
            foreach ( $listItems as $li ) {
                $text = trim((new Crawler($li))->text());
                if ( !empty($text) ) {
                    $descriptionParts[] = '• ' . $text;
                }
            }

            if ( empty($descriptionParts) ) {
                // Fallback: get all text from container
                $text = trim($container->text());
                return !empty($text) ? $text : null;
            }

            return implode("\n", $descriptionParts);
        } catch ( Exception $e ) {
            Log::debug('Error extracting description', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Extract external ID from URL.
     */
    private function extractExternalId (string $url, string $finalUrl) : ?string {
        // Extract product code from URL (usually the last segment)
        $parsed = parse_url($finalUrl);
        $path   = $parsed['path'] ?? '';
        $segments = array_filter(explode('/', $path));
        
        // Nike URLs typically have format: /ca/t/product-name/CODE
        // Return the last segment as external ID
        return end($segments) ?: null;
    }

    /**
     * Extract color links from product page.
     * Filters out sold-out color variations.
     */
    private function extractColorLinks (Crawler $crawler, string $baseUrl) : array {
        $colorLinks = [];

        try {
            $links = $crawler->filter(self::SELECTOR_COLOR_LINK);
            
            foreach ( $links as $link ) {
                $linkCrawler = new Crawler($link);
                
                // // Skip if aria-disabled="true"
                // $ariaDisabled = $linkCrawler->attr('aria-disabled');
                // if ( $ariaDisabled === 'true' ) {
                //     continue;
                // }
                
                // // Skip if has sold-out-slash indicator anywhere within the link element
                // $soldOutSlash = $linkCrawler->filter('[data-testid="sold-out-slash"]');
                // if ( $soldOutSlash->count() > 0 ) {
                //     continue;
                // }//todo: check if this is needed
                
                // Check parent container (color variation wrapper) for sold-out indicator
                try {
                    $linkNode = $linkCrawler->getNode(0);
                    if ( $linkNode && $linkNode->parentNode ) {
                        $parentCrawler = new Crawler($linkNode->parentNode);
                        $parentSoldOut = $parentCrawler->filter('[data-testid="sold-out-slash"]');
                        // if ( $parentSoldOut->count() > 0 ) {
                        //     continue;
                        // }
                        
                        // // Also check if parent has aria-disabled="true"
                        // $parentAriaDisabled = $parentCrawler->attr('aria-disabled');
                        // if ( $parentAriaDisabled === 'true' ) {
                        //     continue;
                        // }//todo: check if this is needed
                    }
                } catch ( Exception $e ) {
                    // If we can't check parent, continue with link check
                }
                
                $href = $linkCrawler->attr('href');
                
                if ( $href ) {
                    $absoluteUrl = $this->normalizeUrl($href, $baseUrl);
                    if ( $absoluteUrl ) {
                        $colorLinks[] = $absoluteUrl;
                    }
                }
            }
        } catch ( Exception $e ) {
            Log::debug('Error extracting color links', ['error' => $e->getMessage()]);
        }

        return array_unique($colorLinks);
    }

    /**
     * Normalize a URL to an absolute URL.
     */
    private function normalizeUrl (string $url, string $baseUrl) : ?string {
        if ( preg_match('/^https?:\/\//', $url) ) {
            return $url;
        }

        // If URL starts with /, it's an absolute path - use base domain
        if ( strpos($url, '/') === 0 ) {
            $parsed = parse_url($baseUrl);
            $scheme = $parsed['scheme'] ?? 'https';
            $host   = $parsed['host'] ?? 'www.nike.com';
            return $scheme . '://' . $host . $url;
        }

        // Relative URL - append to base URL
        $baseUrl = rtrim($baseUrl, '/');
        $url     = ltrim($url, '/');

        return $baseUrl . '/' . $url;
    }

    /**
     * Fetch color variant pages using Http::request.
     */
    private function fetchColorPages (array $colorUrls) : array {
        $results = [];

        foreach ( $colorUrls as $colorUrl ) {
            try {
                $response = Http::timeout(self::HTTP_TIMEOUT)
                    ->connectTimeout(self::HTTP_CONNECT_TIMEOUT)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                        'Accept-Language' => 'en-US,en;q=0.9',
                        'Accept-Encoding' => 'gzip, deflate, br, zstd',
                        'Referer' => self::BASE_URL . '/',
                        'Origin' => self::BASE_URL,
                        'Connection' => 'keep-alive',
                        'Upgrade-Insecure-Requests' => '1',
                        'Sec-Fetch-Dest' => 'document',
                        'Sec-Fetch-Mode' => 'navigate',
                        'Sec-Fetch-Site' => 'none',
                        'Sec-Fetch-User' => '?1',
                        'Cache-Control' => 'max-age=0',
                    ])
                    ->get($colorUrl);

                if ( $response->successful() ) {
                    $html = $response->body();
                    
                    // Save HTML for debugging
                    $this->saveProductHtml($colorUrl, $html);
                    
                    $results[$colorUrl] = [
                        'success' => true,
                        'html' => $html,
                        'error' => null,
                    ];
                } else {
                    $results[$colorUrl] = [
                        'success' => false,
                        'html' => null,
                        'error' => "HTTP {$response->status()}",
                    ];
                }
            } catch ( Exception $e ) {
                $results[$colorUrl] = [
                    'success' => false,
                    'html' => null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Extract data for a color variant page.
     */
    private function extractColorVariantData (Crawler $crawler, string $colorUrl, string $html) : array {
        // Extract color name from URL or page
        $colorLabel = $this->extractColorLabel($crawler, $colorUrl);
        $colorSlug = Str::slug($colorLabel);

        // Extract pricing
        $priceData = $this->extractPrice($crawler);

        // Extract images
        $images = $this->extractImages($crawler);

        // Extract sizes
        $sizes = $this->extractSizes($crawler);

        return [
            'colour_label' => $colorLabel,
            'colour_slug' => $colorSlug,
            'pdp_url' => $colorUrl,
            'price_data' => $priceData,
            'images' => $images,
            'variants' => $sizes,
        ];
    }

    /**
     * Extract color label from page or URL.
     */
    private function extractColorLabel (Crawler $crawler, string $colorUrl) : string {
        // Try to extract from page first
        try {
            $colorSelectors = [
                '[data-testid="colorway-label"]',
                '.colorway-label',
                '[data-testid*="color"]',
            ];

            foreach ( $colorSelectors as $selector ) {
                $element = $crawler->filter($selector)->first();
                if ( $element->count() > 0 ) {
                    $text = trim($element->text());
                    if ( !empty($text) ) {
                        return $text;
                    }
                }
            }
        } catch ( Exception $e ) {
            Log::debug('Error extracting color label from page', ['error' => $e->getMessage()]);
        }

        // Fallback: extract from URL
        $parsed = parse_url($colorUrl);
        $path = $parsed['path'] ?? '';
        $segments = array_filter(explode('/', $path));
        
        // Usually the color is in the URL path
        return end($segments) ?: 'Default';
    }

    /**
     * Extract price from price container.
     */
    private function extractPrice (Crawler $crawler) : array {
        $result = [
            'price' => null,
            'currency' => 'CAD',
            'original_price' => null,
            'discount_percentage' => null,
        ];

        try {
            // First try to find price-container div
            $priceContainer = $crawler->filter(self::SELECTOR_PRICE_CONTAINER)->first();
            
            // If price-container not found, try alternative selectors
            if ( $priceContainer->count() === 0 ) {
                $alternativeSelectors = [
                    '[data-testid="product-price"]',
                    '.product-price',
                    '[data-testid*="price"]',
                    '.price',
                ];
                
                foreach ( $alternativeSelectors as $selector ) {
                    $priceContainer = $crawler->filter($selector)->first();
                    if ( $priceContainer->count() > 0 ) {
                        break;
                    }
                }
            }

            if ( $priceContainer->count() === 0 ) {
                Log::debug('Price container not found, trying to extract from page directly');
                // Try to find price anywhere on the page
                $allPriceElements = $crawler->filter('[data-testid*="price"], .price, [class*="price"]');
                if ( $allPriceElements->count() > 0 ) {
                    $priceContainer = $allPriceElements->first();
                }
            }

            // Extract current price - prioritize specific data-testid attributes
            $currentPriceElement = $crawler->filter('[data-testid="currentPrice-container"]')->first();
            if ( $currentPriceElement->count() > 0 ) {
                $priceText = trim($currentPriceElement->text());
                if ( preg_match('/(?:CAD|USD|\$)?\s*([\d,]+\.?\d*)/', $priceText, $matches) ) {
                    $amount = str_replace(',', '', $matches[1]);
                    $priceValue = (float) $amount;
                    if ( $priceValue >= 10 && $priceValue <= 10000 ) {
                        $result['price'] = $priceValue;
                    }
                }
            }

            // Extract original price - prioritize specific data-testid attribute
            $originalPriceElement = $crawler->filter('[data-testid="initialPrice-container"]')->first();
            if ( $originalPriceElement->count() > 0 ) {
                $originalText = trim($originalPriceElement->text());
                if ( preg_match('/(?:CAD|USD|\$)?\s*([\d,]+\.?\d*)/', $originalText, $matches) ) {
                    $amount = str_replace(',', '', $matches[1]);
                    $originalValue = (float) $amount;
                    if ( $originalValue >= 10 && $originalValue <= 10000 && 
                         ($result['price'] === null || $originalValue > $result['price']) ) {
                        $result['original_price'] = $originalValue;
                    }
                }
            }

            // Extract discount percentage from OfferPercentage
            $discountElement = $crawler->filter('[data-testid="OfferPercentage"]')->first();
            if ( $discountElement->count() > 0 ) {
                $discountText = trim($discountElement->text());
                if ( preg_match('/(\d+(?:\.\d+)?)\s*%/', $discountText, $matches) ) {
                    $result['discount_percentage'] = (float) $matches[1];
                }
            }

            // Fallback: If specific data-testid attributes not found, use container-based extraction
            if ( $result['price'] === null && $priceContainer->count() > 0 ) {
                $priceSelectors = [
                    '[data-testid="product-price"]',
                    '[data-testid*="price"]',
                    '.product-price',
                    '.price',
                    'span',
                    'div',
                ];

                $priceFound = false;
                foreach ( $priceSelectors as $selector ) {
                    $priceElements = $priceContainer->filter($selector);
                    
                    foreach ( $priceElements as $priceElement ) {
                        $elementCrawler = new Crawler($priceElement);
                        $priceText = trim($elementCrawler->text());
                        
                        // Look for price patterns: $XX.XX or CAD $XX.XX or XX.XX
                        if ( preg_match('/(?:CAD|USD|\$)?\s*([\d,]+\.?\d*)/', $priceText, $matches) ) {
                            $amount = str_replace(',', '', $matches[1]);
                            $priceValue = (float) $amount;
                            
                            // Only accept reasonable price values (between 10 and 10000)
                            if ( $priceValue >= 10 && $priceValue <= 10000 ) {
                                $result['price'] = $priceValue;
                                $priceFound = true;
                                break 2;
                            }
                        }
                    }
                    
                    if ( $priceFound ) {
                        break;
                    }
                }
            }

            // Fallback: Extract original price if not found via data-testid
            if ( $result['original_price'] === null && $priceContainer->count() > 0 ) {
                $originalPriceSelectors = [
                    '[data-testid="original-price"]',
                    '[data-testid*="original"]',
                    '.original-price',
                    '.price-original',
                    '[class*="original"]',
                    's', // strikethrough tag
                    'del', // deleted text tag
                ];

                foreach ( $originalPriceSelectors as $selector ) {
                    $originalElements = $priceContainer->filter($selector);
                    
                    if ( $originalElements->count() === 0 ) {
                        // Try in parent context
                        $originalElements = $crawler->filter($selector);
                    }
                    
                    foreach ( $originalElements as $originalElement ) {
                        $elementCrawler = new Crawler($originalElement);
                        $originalText = trim($elementCrawler->text());
                        
                        if ( preg_match('/(?:CAD|USD|\$)?\s*([\d,]+\.?\d*)/', $originalText, $matches) ) {
                            $amount = str_replace(',', '', $matches[1]);
                            $originalValue = (float) $amount;
                            
                            // Only accept if it's higher than current price and reasonable
                            if ( $originalValue >= 10 && $originalValue <= 10000 && 
                                 ($result['price'] === null || $originalValue > $result['price']) ) {
                                $result['original_price'] = $originalValue;
                                break 2;
                            }
                        }
                    }
                }
            }

            // Calculate discount percentage if both prices exist and discount not already extracted
            if ( $result['discount_percentage'] === null && 
                 $result['original_price'] && 
                 $result['price'] && 
                 $result['original_price'] > $result['price'] ) {
                $discount = (($result['original_price'] - $result['price']) / $result['original_price']) * 100;
                $result['discount_percentage'] = round($discount, 2);
            }

        } catch ( Exception $e ) {
            Log::debug('Error extracting price', ['error' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * Extract images from hero image container.
     */
    private function extractImages (Crawler $crawler) : array {
        $images = [];
        $seenUrls = [];

        try {
            $heroContainer = $crawler->filter('div#hero-image')->first();
            if ( $heroContainer->count() > 0 ) {
                $imgElements = $heroContainer->filter('img[data-testid="HeroImg"]');
                
                foreach ( $imgElements as $img ) {
                    $imgCrawler = new Crawler($img);
                    $src = $imgCrawler->attr('src');
                    $alt = $imgCrawler->attr('alt');

                    if ( $src ) {
                        $absoluteUrl = $this->normalizeImageUrl($src);
                        if ( $absoluteUrl && !in_array($absoluteUrl, $seenUrls) ) {
                            $images[] = [
                                'url' => $absoluteUrl,
                                'alt' => $alt,
                            ];
                            $seenUrls[] = $absoluteUrl;
                        }
                    }
                }
            }

            // Fallback: try to find any images in the page
            if ( empty($images) ) {
                $allImages = $crawler->filter('img');
                foreach ( $allImages as $img ) {
                    $imgCrawler = new Crawler($img);
                    $src = $imgCrawler->attr('src');
                    $alt = $imgCrawler->attr('alt');

                    if ( $src && (strpos($src, 'product') !== false || strpos($src, 'hero') !== false) ) {
                        $absoluteUrl = $this->normalizeImageUrl($src);
                        if ( $absoluteUrl && !in_array($absoluteUrl, $seenUrls) ) {
                            $images[] = [
                                'url' => $absoluteUrl,
                                'alt' => $alt,
                            ];
                            $seenUrls[] = $absoluteUrl;
                        }
                    }
                }
            }
        } catch ( Exception $e ) {
            Log::debug('Error extracting images', ['error' => $e->getMessage()]);
        }

        return $images;
    }

    /**
     * Normalize image URL to absolute URL.
     */
    private function normalizeImageUrl (string $url) : ?string {
        if ( preg_match('/^https?:\/\//', $url) ) {
            return $url;
        }

        if ( strpos($url, '//') === 0 ) {
            return 'https:' . $url;
        }

        $baseUrl = rtrim(self::BASE_URL, '/');
        $url     = ltrim($url, '/');

        return $baseUrl . '/' . $url;
    }

    /**
     * Extract available and unavailable sizes.
     */
    private function extractSizes (Crawler $crawler) : array {
        $sizes = [];

        try {
            $sizeItems = $crawler->filter(self::SELECTOR_SIZE_ITEM);
            
            foreach ( $sizeItems as $item ) {
                $itemCrawler = new Crawler($item);
                
                // Check if disabled
                $isDisabled = $itemCrawler->filter('.disabled')->count() > 0 || 
                             $itemCrawler->hasClass('disabled');
                
                // Extract size text
                $sizeText = trim($itemCrawler->text());
                
                if ( !empty($sizeText) ) {
                    $sizes[] = [
                        'size' => $sizeText,
                        'stock_availability' => !$isDisabled,
                    ];
                }
            }
        } catch ( Exception $e ) {
            Log::debug('Error extracting sizes', ['error' => $e->getMessage()]);
        }

        return $sizes;
    }

    /**
     * Deduplicate images by URL.
     */
    private function deduplicateImages (array $images) : array {
        $seen = [];
        $deduplicated = [];

        foreach ( $images as $image ) {
            $url = $image['url'] ?? '';
            if ( !empty($url) && !isset($seen[$url]) ) {
                $seen[$url] = true;
                $deduplicated[] = $image;
            }
        }

        return $deduplicated;
    }

    /**
     * Download and save image from URL.
     */
    private function downloadAndSaveImage (string $imageUrl, Product $product, string $slug, int $imageIndex) : ?string {
        try {
            $response = Http::timeout(60)
                ->connectTimeout(30)
                ->withHeaders([
                    'Accept' => 'image/*',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                ])
                ->get($imageUrl);

            if ( !$response->successful() ) {
                Log::warning('Failed to download image', [
                    'product_id' => $product->id,
                    'url' => $imageUrl,
                    'status' => $response->status(),
                ]);
                return null;
            }

            // Determine extension from URL or Content-Type
            $extension = 'jpg';
            $contentType = $response->header('Content-Type');
            if ( $contentType ) {
                if ( strpos($contentType, 'png') !== false ) {
                    $extension = 'png';
                } elseif ( strpos($contentType, 'webp') !== false ) {
                    $extension = 'webp';
                }
            } else {
                $pathInfo = pathinfo(parse_url($imageUrl, PHP_URL_PATH));
                if ( isset($pathInfo['extension']) ) {
                    $extension = strtolower($pathInfo['extension']);
                }
            }

            // Generate filename: nike_{product_id}_{slug}_{index}_{timestamp}.{ext}
            $variant = $imageIndex > 0 ? $imageIndex : '';
            $timestamp = time();
            $filename = $this->generateImageFilename($product->id, $slug, $variant, $timestamp, $extension);

            // Save to storage/app/public/images/nike/
            $path = "images/nike/{$filename}";
            Storage::disk('public')->put($path, $response->body());

            return $path;
        } catch ( Exception $e ) {
            Log::error('Error downloading image', [
                'product_id' => $product->id,
                'url' => $imageUrl,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Generate image filename.
     */
    private function generateImageFilename (int $productId, string $slug, $variant, int $timestamp, string $extension = 'jpg') : string {
        $cleanSlug = Str::slug($slug);
        if ( empty($cleanSlug) ) {
            $cleanSlug = 'product';
        }

        $variantPart = '';
        if ( $variant !== '' && $variant !== null ) {
            $variantPart = '_' . (is_string($variant) ? Str::slug($variant) : (string) $variant);
        }

        return "nike_{$productId}_{$cleanSlug}{$variantPart}_{$timestamp}.{$extension}";
    }

    /**
     * Enrich variations matrix with local image paths.
     * Uses local_path from Playwright images instead of downloading again.
     */
    private function enrichVariationsMatrixWithLocalImages (array $variationsMatrix, Product $product, string $slug) : array {
        foreach ( $variationsMatrix as $i => $colorVariant ) {
            $colorImages = $colorVariant['images'] ?? [];
            $downloadedPaths = [];

            if ( !empty($colorImages) && is_array($colorImages) ) {
                foreach ( $colorImages as $imageIndex => $image ) {
                    $imageUrl = $image['url'] ?? '';
                    $localPath = $image['local_path'] ?? null;
                    
                    if ( empty($imageUrl) ) {
                        continue;
                    }

                    // Use local_path from Playwright if available (already downloaded to variation folder)
                    // local_path already includes images/nike/ prefix from extractHeroImagesWithPlaywright
                    if ( $localPath ) {
                        $downloadedPaths[] = $localPath;
                    }
                    // If no local_path, image wasn't downloaded by Playwright, skip it
                    // (We don't want to download to main folder anymore)
                }
            }

            $variationsMatrix[$i]['images_local'] = $downloadedPaths;
        }

        return $variationsMatrix;
    }

    /**
     * Download and save variant image.
     */
    private function downloadAndSaveVariantImage (string $imageUrl, Product $product, string $slug, string $colorSlug, int $imageIndex) : ?string {
        try {
            $response = Http::timeout(60)
                ->connectTimeout(30)
                ->withHeaders([
                    'Accept' => 'image/*',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                ])
                ->get($imageUrl);

            if ( !$response->successful() ) {
                return null;
            }

            $extension = 'jpg';
            $contentType = $response->header('Content-Type');
            if ( $contentType ) {
                if ( strpos($contentType, 'png') !== false ) {
                    $extension = 'png';
                } elseif ( strpos($contentType, 'webp') !== false ) {
                    $extension = 'webp';
                }
            }

            $timestamp = time();
            $filename = $this->generateVariantImageFilename($product->id, $slug, $colorSlug, $imageIndex, $timestamp, $extension);

            // Save to storage/app/public/images/nike/
            $path = "images/nike/{$filename}";
            Storage::disk('public')->put($path, $response->body());

            return $path;
        } catch ( Exception $e ) {
            Log::error('Error downloading variant image', [
                'product_id' => $product->id,
                'url' => $imageUrl,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Generate variant image filename.
     */
    private function generateVariantImageFilename (int $productId, string $slug, string $colorSlug, int $imageIndex, int $timestamp, string $extension = 'jpg') : string {
        $cleanSlug = Str::slug($slug);
        if ( empty($cleanSlug) ) {
            $cleanSlug = 'product';
        }

        $cleanColorSlug = Str::slug($colorSlug);
        if ( empty($cleanColorSlug) ) {
            $cleanColorSlug = 'default';
        }

        return "nike_{$productId}_{$cleanSlug}_{$cleanColorSlug}_{$imageIndex}_{$timestamp}.{$extension}";
    }

    /**
     * Extract hero images using Playwright script by clicking through thumbnails.
     * This method calls the Node.js Playwright script to get all hero images.
     *
     * @param string $productUrl Base product URL
     * @param string|null $variationUrl Variation/color URL (optional)
     *
     * @return array Array of image data with 'url' and 'localPath' keys
     */
    private function extractHeroImagesWithPlaywright (string $productUrl, ?string $variationUrl = null) : array {
        try {
            $scriptPath = base_path('scripts/nike-hero-images-scraper.js');
            if ( !file_exists($scriptPath) ) {
                Log::warning('Playwright script not found', [
                    'script_path' => $scriptPath,
                    'product_url' => $productUrl,
                ]);
                return [];
            }

            $outputDir = storage_path('app/public/images/nike');
            
            // Build command
            $command = [
                'node',
                $scriptPath,
                $productUrl,
                $variationUrl ?: '',
                $outputDir,
            ];

            Log::info('Calling Playwright script for hero images', [
                'product_url' => $productUrl,
                'variation_url' => $variationUrl,
                'output_dir' => $outputDir,
            ]);

            $process = new Process($command);
            $process->setTimeout(600); // 10 minutes timeout (needed for clicking through all thumbnails and downloading images)
            
            // Capture both stdout and stderr
            $process->run(function ($type, $buffer) use ($productUrl, $variationUrl) {
                // Log output in real-time
                if (Process::ERR === $type) {
                    Log::debug('Playwright script stderr', [
                        'product_url' => $productUrl,
                        'variation_url' => $variationUrl,
                        'output' => $buffer,
                    ]);
                } else {
                    Log::debug('Playwright script stdout', [
                        'product_url' => $productUrl,
                        'variation_url' => $variationUrl,
                        'output' => $buffer,
                    ]);
                }
            });

            // Get all output
            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();
            
            // Save full output to log file
            $logDir = storage_path('logs/nike/playwright');
            if ( !is_dir($logDir) ) {
                mkdir($logDir, 0755, true);
            }
            
            $productSlug = $this->extractSlug($productUrl);
            $variationId = $variationUrl ? $this->extractSlug($variationUrl) : 'default';
            $logFilename = sprintf(
                'playwright-%s-%s-%s.log',
                $productSlug,
                $variationId,
                date('Y-m-d_H-i-s')
            );
            $logPath = $logDir . '/' . $logFilename;
            
            $logContent = sprintf(
                "=== Playwright Script Output ===\n" .
                "Product URL: %s\n" .
                "Variation URL: %s\n" .
                "Timestamp: %s\n" .
                "Exit Code: %d\n" .
                "\n--- STDOUT ---\n%s\n" .
                "\n--- STDERR ---\n%s\n" .
                "=== End of Output ===\n",
                $productUrl,
                $variationUrl ?: 'N/A',
                date('Y-m-d H:i:s'),
                $process->getExitCode(),
                $stdout,
                $stderr
            );
            
            file_put_contents($logPath, $logContent);
            Log::info('Playwright script output saved', [
                'product_url' => $productUrl,
                'variation_url' => $variationUrl,
                'log_path' => $logPath,
            ]);

            if ( !$process->isSuccessful() ) {
                Log::warning('Playwright script failed', [
                    'product_url' => $productUrl,
                    'variation_url' => $variationUrl,
                    'error' => $stderr ?: $stdout,
                    'exit_code' => $process->getExitCode(),
                    'log_path' => $logPath,
                ]);
                return [];
            }

            // Read results JSON file
            // Extract product slug from URL (last segment)
            $parsedProductUrl = parse_url($productUrl);
            $productPath = $parsedProductUrl['path'] ?? '';
            $productSegments = array_filter(explode('/', $productPath));
            $productSlug = end($productSegments) ?: 'product';
            
            // Extract variation ID from URL (last segment) or use 'default'
            $variationId = 'default';
            if ( $variationUrl ) {
                $parsedVariationUrl = parse_url($variationUrl);
                $variationPath = $parsedVariationUrl['path'] ?? '';
                $variationSegments = array_filter(explode('/', $variationPath));
                $variationId = end($variationSegments) ?: 'default';
            }
            
            $resultsPath = $outputDir . '/' . $productSlug . '/' . $variationId . '/results.json';

            if ( !file_exists($resultsPath) ) {
                Log::warning('Playwright results file not found', [
                    'results_path' => $resultsPath,
                    'product_url' => $productUrl,
                ]);
                return [];
            }

            $resultsJson = file_get_contents($resultsPath);
            $results = json_decode($resultsJson, true);

            if ( !isset($results['images']) || !is_array($results['images']) ) {
                Log::warning('Invalid Playwright results structure', [
                    'results_path' => $resultsPath,
                ]);
                return [];
            }

            // Convert Playwright results to image array format
            $images = [];
            foreach ( $results['images'] as $imageData ) {
                $heroImgSrc = $imageData['heroImgSrc'] ?? null;
                $localPath = $imageData['localPath'] ?? null;

                if ( $heroImgSrc ) {
                    // Playwright returns localPath as: productSlug/variationId/filename
                    // Prepend images/nike/ to get full storage path
                    $fullLocalPath = $localPath ? "images/nike/{$localPath}" : null;
                    
                    $images[] = [
                        'url' => $heroImgSrc,
                        'alt' => null,
                        'local_path' => $fullLocalPath, // Full path: images/nike/productSlug/variationId/filename
                        'playwright_index' => $imageData['index'] ?? null,
                    ];
                }
            }

            Log::info('Playwright script completed successfully', [
                'product_url' => $productUrl,
                'variation_url' => $variationUrl,
                'images_found' => count($images),
            ]);

            return $images;

        } catch ( Exception $e ) {
            Log::error('Error calling Playwright script', [
                'product_url' => $productUrl,
                'variation_url' => $variationUrl,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}

