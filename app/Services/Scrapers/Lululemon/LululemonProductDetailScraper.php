<?php

declare(strict_types=1);

namespace App\Services\Scrapers\Lululemon;

use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductMedia;
use App\Models\Website;
use App\Services\AI\Gemini\GeminiFunctions;
use App\Services\Utils\NodeRenderService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;

class LululemonProductDetailScraper {
    private const BASE_URL                  = 'https://www.eu.lululemon.com';
    private const DEFAULT_CONCURRENCY       = 3; // Lower for Puppeteer to avoid Cloudflare
    private const DEFAULT_BATCH_SIZE        = 50; // Smaller batches for Puppeteer
    private const HTTP_TIMEOUT              = 300; // 5 minutes for Puppeteer
    private const GC_COLLECT_INTERVAL       = 5;
    private const MAX_RETRIES               = 3;
    private const RETRY_DELAY_BASE          = 2;

    // Selectors
    private const SELECTOR_PRODUCT_NAME     = 'h1.product-name.product-name-new.d-lg-block';
    private const SELECTOR_DESCRIPTION      = 'div.why-we-made-this__text-container';
    private const SELECTOR_ACCORDION        = 'div#newAccordion.container.accordion.pdp-new-accordion.optimized-accordion';
    private const SELECTOR_SALE_TAG         = '.sale-tag';
    private const SELECTOR_VARIATION        = '[data-color-title]';
    private const SELECTOR_THUMBNAILS       = 'div#thumbnails';
    private const SELECTOR_SIZE_INPUT       = 'div.custom-select-btn input[type="radio"]';

    private Website $website;
    private NodeRenderService $nodeRenderService;

    public function __construct(Website $website, ?NodeRenderService $nodeRenderService = null) {
        $this->website = $website;
        $this->nodeRenderService = $nodeRenderService ?? new NodeRenderService();
    }

    /**
     * Scrape products from URLs using Puppeteer for rendering.
     *
     * @param array    $productUrls Array of absolute product URLs
     * @param int      $concurrency Number of concurrent requests
     * @param int      $batchSize   Number of URLs to process per batch
     * @param int|null $maxProducts Maximum number of products to scrape (null = no limit)
     * @param float    $batchSleep  Sleep time in seconds between batches
     *
     * @return void
     */
    public function scrapeProducts(
        array $productUrls, int $concurrency = self::DEFAULT_CONCURRENCY, int $batchSize = self::DEFAULT_BATCH_SIZE, ?int $maxProducts = null, float $batchSleep = 2.0
    ): void {
        $productUrls = array_values(array_unique($productUrls));

        if ($maxProducts !== null && $maxProducts > 0) {
            $productUrls = array_slice($productUrls, 0, $maxProducts);
        }

        $totalUrls = count($productUrls);

        Log::info('Starting Lululemon product detail scraping', [
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

        foreach ($urlBatches as $batchIndex => $urlBatch) {
            $batchNumber    = $batchIndex + 1;
            $batchStartTime = microtime(true);

            Log::info("Processing URL batch {$batchNumber} of {$totalBatches}", [
                'batch_size'       => count($urlBatch),
                'processed_so_far' => $processed,
            ]);

            // Process URLs in smaller concurrent batches
            // Note: True parallelism requires process pools, but we batch to manage resources
            $concurrencyBatches = array_chunk($urlBatch, $concurrency);
            
            foreach ($concurrencyBatches as $concurrencyBatchIndex => $concurrencyBatch) {
                Log::debug("Processing concurrency batch", [
                    'batch' => $concurrencyBatchIndex + 1,
                    'size' => count($concurrencyBatch),
                ]);
                
                // Process each URL in the batch
                foreach ($concurrencyBatch as $url) {
                    try {
                        $this->fetchAndProcessProduct($url);
                        $successful++;
                        $processed++;
                    } catch (Exception $e) {
                        $failed++;
                        $processed++;
                        Log::error('Error processing product', [
                            'url'   => $url,
                            'error' => $e->getMessage(),
                        ]);
                    }
                    
                    // Delay between products to avoid triggering Cloudflare
                    if ($batchSleep > 0) {
                        // Random delay between 1-3 seconds to appear more human-like
                        $delay = 1 + (rand(0, 20) / 10); // 1.0 to 3.0 seconds
                        usleep((int)($delay * 1000000));
                    }
                }
                
                // Small delay between concurrency batches
                if ($concurrencyBatchIndex < count($concurrencyBatches) - 1 && $batchSleep > 0) {
                    usleep((int)($batchSleep * 500000));
                }
            }

            $batchDuration = microtime(true) - $batchStartTime;
            $elapsed       = microtime(true) - $startTime;
            $rate          = $processed > 0 ? round($processed / $elapsed, 2) : 0;

            Log::info("URL batch {$batchNumber} completed", [
                'processed'      => $processed,
                'successful'      => $successful,
                'failed'         => $failed,
                'progress'       => round(($processed / $totalUrls) * 100, 2) . '%',
                'batch_duration' => round($batchDuration, 2) . 's',
                'overall_rate'   => $rate . ' products/s',
            ]);

            if ($batchIndex < $totalBatches - 1 && $batchSleep > 0) {
                sleep((int)$batchSleep);
            }

            if ($batchNumber % self::GC_COLLECT_INTERVAL === 0) {
                gc_collect_cycles();
            }
        }

        $totalDuration = microtime(true) - $startTime;
        Log::info('Lululemon product detail scraping completed', [
            'total_processed' => $processed,
            'successful'      => $successful,
            'failed'         => $failed,
            'total_duration' => round($totalDuration, 2) . 's',
            'average_rate'   => $processed > 0 ? round($processed / $totalDuration, 2) . ' products/s' : '0 products/s',
        ]);
    }

    /**
     * Fetch and process a single product page.
     */
    private function fetchAndProcessProduct(string $url): void {
        // Fetch HTML using Puppeteer with color swatch interactions
        $html = $this->nodeRenderService->getProductDetailHtml($url, self::HTTP_TIMEOUT);

        if (empty($html)) {
            throw new RuntimeException("Failed to fetch product page: {$url}");
        }

        // Log the HTML to file for debugging
        $this->logHtmlToFile($url, $html);

        $this->parseAndSaveProduct($url, $html);
    }

    /**
     * Log HTML content to file for debugging.
     */
    private function logHtmlToFile(string $url, string $html): void {
        try {
            $logDir = storage_path('logs/lululemon');
            if (!file_exists($logDir)) {
                mkdir($logDir, 0755, true);
            }

            // Generate filename from URL slug and timestamp
            $slug = $this->extractSlug($url);
            $timestamp = now()->format('Y-m-d_H-i-s');
            $filename = "product-html-{$slug}-{$timestamp}.html";
            $filePath = $logDir . '/' . $filename;

            file_put_contents($filePath, $html);

            Log::info('Saved Puppeteer HTML to log file', [
                'url' => $url,
                'file_path' => $filePath,
                'file_size' => filesize($filePath),
                'html_length' => strlen($html),
            ]);
        } catch (Exception $e) {
            Log::warning('Failed to save HTML to log file', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Parse HTML and save product to database.
     */
    private function parseAndSaveProduct(string $url, string $html): void {
        $crawler = new Crawler($html);

        $slug            = $this->extractSlug($url);
        $title            = $this->extractTitle($crawler);
        $description      = $this->extractDescription($crawler);
        $accordionSections = $this->extractAccordionSections($crawler);
        $images           = $this->extractImages($crawler);
        $variations       = $this->extractVariations($crawler);
        
        Log::info('Extracted product data', [
            'url' => $url,
            'title' => $title,
            'variations_count' => count($variations),
            'images_count' => count($images),
            'accordion_sections' => count($accordionSections),
        ]);

        // Translate to Persian using Gemini AI
        $geminiFunctions = new GeminiFunctions();
        $nameTranslated = null;
        $descriptionTranslated = null;
        $accordionTranslated = null;

        // Translate product name
        if (!empty($title)) {
            try {
                $nameTranslated = $geminiFunctions->translateToPersian($title);
            } catch (Exception $e) {
                Log::warning('Failed to translate product name to Persian', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Translate description
        if (!empty($description)) {
            try {
                Log::debug('Translating product description to Persian', [
                    'url' => $url,
                    'description_length' => strlen($description),
                ]);
                $descriptionTranslated = $geminiFunctions->translateToPersian($description);
                Log::info('Successfully translated product description', [
                    'url' => $url,
                    'translated_length' => strlen($descriptionTranslated ?? ''),
                ]);
            } catch (Exception $e) {
                Log::warning('Failed to translate product description to Persian', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                    'description_preview' => substr($description, 0, 100) . '...',
                ]);
            }
        } else {
            Log::debug('No description to translate', ['url' => $url]);
        }

        // Translate accordion sections
        if (!empty($accordionSections)) {
            try {
                $accordionText = implode("\n\n", array_map(function($section) {
                    return ($section['title'] ? $section['title'] . "\n" : '') . $section['content'];
                }, $accordionSections));
                
                if (!empty($accordionText)) {
                    $accordionTranslated = $geminiFunctions->translateToPersian($accordionText);
                }
            } catch (Exception $e) {
                Log::warning('Failed to translate accordion sections to Persian', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Determine base price from variations (lowest sale price or original price)
        $priceData = $this->extractPriceFromVariations($variations);

        unset($crawler);

        $rawData = [
            'url'            => $url,
            'title'          => $title,
            'description'    => $description,
            'accordion'      => $accordionSections,
            'price'          => $priceData['price'],
            'currency'       => $priceData['currency'],
            'original_price' => $priceData['originalPrice'] ?? null,
            'images'         => $images,
            'variant_matrix' => $variations,
        ];

        // Build meta array with translations
        $meta = [
            'brand' => 'Lululemon',
        ];

        if ($nameTranslated !== null) {
            $meta['name_translated'] = $nameTranslated;
        }

        if ($descriptionTranslated !== null) {
            $meta['description_translated'] = $descriptionTranslated;
            Log::debug('Added description translation to meta', [
                'url' => $url,
                'translation_length' => strlen($descriptionTranslated),
            ]);
        } else {
            Log::debug('No description translation available', [
                'url' => $url,
                'has_description' => !empty($description),
            ]);
        }

        if ($accordionTranslated !== null) {
            $meta['accordion_translated'] = $accordionTranslated;
        }

        // Store accordion sections in meta
        if (!empty($accordionSections)) {
            $meta['accordion_sections'] = $accordionSections;
        }

        DB::transaction(function () use ($slug, $title, $description, $priceData, $images, &$variations, $rawData, $meta, $url, $nameTranslated, $descriptionTranslated, $accordionTranslated) {
            // Generate external_id from URL slug
            $externalId = $slug;

            // Check if product already exists to preserve existing meta data
            $existingProduct = Product::where('website_id', $this->website->id)
                ->where('external_id', $externalId)
                ->first();

            // Merge with existing meta if product exists
            if ($existingProduct && !empty($existingProduct->meta) && is_array($existingProduct->meta)) {
                $meta = array_merge($existingProduct->meta, $meta);
            }

            $product = Product::updateOrCreate([
                'website_id'  => $this->website->id,
                'external_id' => $externalId,
            ], [
                'title'          => $title,
                'slug'           => $slug,
                'description'    => $description,
                'price'          => $priceData['price'],
                'currency'       => $priceData['currency'],
                'stock_quantity' => $this->calculateStockQuantity($variations),
                'status'         => $this->calculateStatus($variations),
                'raw_data'       => $rawData,
                'meta'           => $meta,
            ]);

            $product->media()->delete();
            $product->attributes()->delete();

            // Download and save main product images (from first variation or base images)
            if (!empty($images)) {
                $mediaData = [];
                $isFirst = true;
                foreach ($images as $imageIndex => $image) {
                    $imageUrl = $image['url'] ?? '';
                    if (empty($imageUrl)) {
                        continue;
                    }

                    // Download and save image
                    $localPath = $this->downloadAndSaveImage($imageUrl, $product, $slug, $imageIndex);

                    if ($localPath) {
                        $mediaData[] = [
                            'product_id' => $product->id,
                            'type'       => 'image',
                            'source_url' => $localPath, // Store relative path instead of URL
                            'local_path' => $localPath,
                            'alt_text'   => $image['alt'] ?? null,
                            'is_primary' => $isFirst,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                        $isFirst = false;
                    }
                }
                
                if (!empty($mediaData)) {
                    ProductMedia::insert($mediaData);
                }
                unset($mediaData);
            }

            // Process variations and download their images
            if (!empty($variations)) {
                // Enrich variant matrix with local image paths (like Calvin Klein)
                $variations = $this->enrichVariantMatrixWithLocalImages($variations, $product, $slug);

                // Transform Lululemon format to Calvin Klein format for WordpressSyncService compatibility
                $variantMatrix = $this->transformVariationsToCalvinKleinFormat($variations);

                $variantAttributeData = [];

                // 1. variant_matrix - full JSON (like Calvin Klein)
                $variantAttributeData[] = [
                    'product_id' => $product->id,
                    'name'       => 'variant_matrix',
                    'value'      => json_encode($variantMatrix, JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                // 2. available_colours - JSON array of colours with stock (like Calvin Klein)
                $availableColours = collect($variantMatrix)->filter(function ($colourway) {
                        return collect($colourway['variants'] ?? [])->contains(fn($v) => !empty($v['stock_availability']));
                    })->pluck('colour_label')->unique()->values()->all();

                if (!empty($availableColours)) {
                    $variantAttributeData[] = [
                        'product_id' => $product->id,
                        'name'       => 'available_colours',
                        'value'      => json_encode($availableColours, JSON_UNESCAPED_UNICODE),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                // 3. available_sizes_by_colour - compact size availability map (like Calvin Klein)
                $sizeMap = [];
                foreach ($variantMatrix as $colourway) {
                    $colourLabel = $colourway['colour_label'] ?? $colourway['colour'] ?? null;
                    if (!$colourLabel || empty($colourway['variants'])) {
                        continue;
                    }

                    foreach ($colourway['variants'] as $variant) {
                        $size = $variant['size'] ?? null;
                        if (!$size) {
                            continue;
                        }
                        $inStock = !empty($variant['stock_availability']);
                        $sizeMap[$colourLabel][$size] = $inStock;
                    }
                }

                if (!empty($sizeMap)) {
                    $variantAttributeData[] = [
                        'product_id' => $product->id,
                        'name'       => 'available_sizes_by_colour',
                        'value'      => json_encode($sizeMap, JSON_UNESCAPED_UNICODE),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if (!empty($variantAttributeData)) {
                    ProductAttribute::insert($variantAttributeData);
                    unset($variantAttributeData);
                }
            }
        });

        // Final logging after transaction
        $saleCount = count($variations);
        $imageCount = count($images);
        $totalVariationImages = 0;
        foreach ($variations as $variation) {
            $totalVariationImages += count($variation['images'] ?? []);
        }
        
        Log::info('Product saved successfully', [
            'url' => $url,
            'title' => $title,
            'sale_variations_count' => $saleCount,
            'main_images_saved' => $imageCount,
            'variation_images_saved' => $totalVariationImages,
            'total_images_saved' => $imageCount + $totalVariationImages,
            'has_variant_matrix' => !empty($variations),
            'has_translations' => !empty($nameTranslated) || !empty($descriptionTranslated) || !empty($accordionTranslated),
            'name_translated' => !empty($nameTranslated),
            'description_translated' => !empty($descriptionTranslated),
            'accordion_translated' => !empty($accordionTranslated),
        ]);

        unset($rawData, $images, $variations);
    }

    /**
     * Extract slug from URL.
     */
    private function extractSlug(string $url): string {
        $parsed   = parse_url($url);
        $path     = $parsed['path'] ?? '';
        $segments = array_filter(explode('/', $path));

        return end($segments) ?: 'unknown';
    }

    /**
     * Extract product title.
     */
    private function extractTitle(Crawler $crawler): ?string {
        try {
            $element = $crawler->filter(self::SELECTOR_PRODUCT_NAME)->first();
            if ($element->count() > 0) {
                return trim($element->text());
            }
        } catch (Exception $e) {
            Log::debug('Error extracting title', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Extract product description from why-we-made-this section only.
     */
    private function extractDescription(Crawler $crawler): string {
        try {
            $whyWeMadeThis = $crawler->filter(self::SELECTOR_DESCRIPTION)->first();
            if ($whyWeMadeThis->count() > 0) {
                $text = trim($whyWeMadeThis->text());
                return $text;
            }
        } catch (Exception $e) {
            Log::debug('Error extracting description', ['error' => $e->getMessage()]);
        }

        return '';
    }

    /**
     * Extract accordion sections (Fit and Features, Fabric, Materials and Care).
     */
    private function extractAccordionSections(Crawler $crawler): array {
        $accordionData = [];

        try {
            $accordion = $crawler->filter(self::SELECTOR_ACCORDION)->first();
            if ($accordion->count() > 0) {
                // Strategy 1: Look for card-header with data-toggle="collapse" (Bootstrap accordion)
                // Also look for accordion-section with data-toggle
                $accordionHeaders = $accordion->filter('.card-header[data-toggle="collapse"], .accordion-section[data-toggle="collapse"], [data-toggle="collapse"][href^="#"]');
                
                foreach ($accordionHeaders as $header) {
                    $headerCrawler = new Crawler($header);
                    
                    // Get section title from h3.accordion-title or .card-title
                    $titleElement = $headerCrawler->filter('h3.accordion-title, .accordion-title, .card-title h3, .card-title h3, h3')->first();
                    $title = $titleElement->count() > 0 ? trim($titleElement->text()) : '';
                    
                    // Get the target collapse element
                    $targetId = $headerCrawler->attr('href') ?? $headerCrawler->attr('data-target') ?? '';
                    $targetId = ltrim($targetId, '#');
                    
                    $content = '';
                    if (!empty($targetId)) {
                        // Find the collapse content element - search in the accordion container
                        $contentElement = $accordion->filter("#{$targetId}");
                        if ($contentElement->count() === 0) {
                            // Try without # prefix
                            $contentElement = $crawler->filter("#{$targetId}");
                        }
                        
                        if ($contentElement->count() > 0) {
                            // Get content from collapse body
                            $bodyElement = $contentElement->filter('.collapse-body, .card-body, .accordion-body, .collapse-content, .collapse.show, .collapse');
                            if ($bodyElement->count() > 0) {
                                $content = trim($bodyElement->text());
                            } else {
                                $content = trim($contentElement->text());
                            }
                        }
                    }
                    
                    // If no content found via target, try to find next sibling with collapse class
                    if (empty($content)) {
                        try {
                            $node = $headerCrawler->getNode(0);
                            if ($node) {
                                // Look for next sibling
                                $nextSibling = $node->nextSibling;
                                while ($nextSibling) {
                                    if ($nextSibling instanceof \DOMElement) {
                                        $siblingCrawler = new Crawler($nextSibling);
                                        $class = $siblingCrawler->attr('class') ?? '';
                                        if (strpos($class, 'collapse') !== false || strpos($class, 'card-body') !== false) {
                                            $content = trim($siblingCrawler->text());
                                            break;
                                        }
                                    }
                                    $nextSibling = $nextSibling->nextSibling;
                                }
                                
                                // Also check parent's children
                                if (empty($content) && $node->parentNode) {
                                    foreach ($node->parentNode->childNodes as $sibling) {
                                        if ($sibling instanceof \DOMElement && $sibling !== $node) {
                                            $siblingCrawler = new Crawler($sibling);
                                            $class = $siblingCrawler->attr('class') ?? '';
                                            if (strpos($class, 'collapse') !== false || strpos($class, 'card-body') !== false) {
                                                $content = trim($siblingCrawler->text());
                                                break;
                                            }
                                        }
                                    }
                                }
                            }
                        } catch (Exception $e) {
                            Log::debug('Error finding accordion content sibling', ['error' => $e->getMessage()]);
                        }
                    }
                    
                    if (!empty($title) || !empty($content)) {
                        $accordionData[] = [
                            'title' => $title,
                            'content' => $content,
                        ];
                    }
                    unset($headerCrawler);
                }
                
                // Strategy 2: If no headers found, try accordion items
                if (empty($accordionData)) {
                    $accordionItems = $accordion->filter('.accordion-item, .accordion__item, [data-accordion-item], .accordion-panel, .card');
                    foreach ($accordionItems as $item) {
                        $itemCrawler = new Crawler($item);
                        
                        // Get section title
                        $titleElement = $itemCrawler->filter('.accordion-header, .accordion-title, h3, h4, [data-accordion-header], .card-title h3')->first();
                        $title = $titleElement->count() > 0 ? trim($titleElement->text()) : '';
                        
                        // Get section content
                        $contentElement = $itemCrawler->filter('.accordion-body, .accordion-content, .accordion-panel-body, .card-body, .collapse-body')->first();
                        $content = $contentElement->count() > 0 ? trim($contentElement->text()) : '';
                        
                        if (empty($content)) {
                            $content = trim($itemCrawler->text());
                        }
                        
                        if (!empty($title) || !empty($content)) {
                            $accordionData[] = [
                                'title' => $title,
                                'content' => $content,
                            ];
                        }
                        unset($itemCrawler);
                    }
                }
            }
        } catch (Exception $e) {
            Log::debug('Error extracting accordion sections', ['error' => $e->getMessage()]);
        }

        return $accordionData;
    }

    /**
     * Extract product images from thumbnails section (1600x1600).
     * Collect all full-size image URLs from <a href="..."> in #thumbnails.
     */
    private function extractImages(Crawler $crawler): array {
        $images   = [];
        $seenUrls = [];

        try {
            $thumbnails = $crawler->filter(self::SELECTOR_THUMBNAILS)->first();
            
            if ($thumbnails->count() > 0) {
                // Get all <a> links in thumbnails (these contain full-size image URLs)
                $thumbnailLinks = $thumbnails->filter('a[href]');
                
                foreach ($thumbnailLinks as $link) {
                    $linkCrawler = new Crawler($link);
                    $href = $linkCrawler->attr('href');
                    
                    if ($href) {
                        // Normalize image URL to 1600x1600
                        $normalizedUrl = $this->normalizeImageUrl($href);
                        if ($normalizedUrl && !in_array($normalizedUrl, $seenUrls)) {
                            // Try to get alt text from img inside the link
                            $img = $linkCrawler->filter('img')->first();
                            $alt = $img->count() > 0 ? ($img->attr('alt') ?? '') : '';
                            
                            $images[] = [
                                'url' => $normalizedUrl,
                                'alt' => $alt,
                            ];
                            $seenUrls[] = $normalizedUrl;
                        }
                    }
                    unset($linkCrawler);
                }
                
                // Also check for direct img tags in thumbnails
                $thumbnailImages = $thumbnails->filter('img');
                foreach ($thumbnailImages as $img) {
                    $imgCrawler = new Crawler($img);
                    $src = $imgCrawler->attr('src') ?? $imgCrawler->attr('data-src') ?? $imgCrawler->attr('data-lazy-src');
                    $alt = $imgCrawler->attr('alt') ?? '';

                    if ($src) {
                        $normalizedUrl = $this->normalizeImageUrl($src);
                        if ($normalizedUrl && !in_array($normalizedUrl, $seenUrls)) {
                            $images[] = [
                                'url' => $normalizedUrl,
                                'alt' => $alt,
                            ];
                            $seenUrls[] = $normalizedUrl;
                        }
                    }
                    unset($imgCrawler);
                }
            }

            // Fallback: try to find images anywhere on the page
            if (empty($images)) {
                $allImages = $crawler->filter('img[src*="lululemon"], img[data-src*="lululemon"]');
                foreach ($allImages as $img) {
                    $imgCrawler = new Crawler($img);
                    $src = $imgCrawler->attr('src') ?? $imgCrawler->attr('data-src') ?? $imgCrawler->attr('data-lazy-src');
                    $alt = $imgCrawler->attr('alt') ?? '';

                    if ($src) {
                        $normalizedUrl = $this->normalizeImageUrl($src);
                        if ($normalizedUrl && !in_array($normalizedUrl, $seenUrls)) {
                            $images[] = [
                                'url' => $normalizedUrl,
                                'alt' => $alt,
                            ];
                            $seenUrls[] = $normalizedUrl;
                        }
                    }
                    unset($imgCrawler);
                }
            }
        } catch (Exception $e) {
            Log::debug('Error extracting images', ['error' => $e->getMessage()]);
        }

        return $images;
    }

    /**
     * Extract images for a specific color variation.
     * First tries to get color-specific images captured by Puppeteer, then falls back to DOM extraction.
     */
    private function extractImagesForColor(Crawler $crawler, string $colorTitle, Crawler $colorButton = null): array {
        $images = [];
        $seenUrls = [];

        try {
            // Strategy 1: Extract color-specific images from Puppeteer's injected data
            // Puppeteer captures images after each color click and stores them in a script tag
            $scriptTags = $crawler->filter('script#lululemon-color-variations-data');
            if ($scriptTags->count() > 0) {
                // Try to get data from data-variations attribute first
                $dataAttr = $scriptTags->attr('data-variations');
                if ($dataAttr) {
                    try {
                        $colorVariationsData = json_decode($dataAttr, true);
                        if (isset($colorVariationsData[$colorTitle]) && is_array($colorVariationsData[$colorTitle])) {
                            $images = $colorVariationsData[$colorTitle];
                            Log::debug('Extracted color-specific images from data-variations attribute', [
                                'color' => $colorTitle,
                                'count' => count($images),
                            ]);
                            return $images;
                        }
                    } catch (Exception $e) {
                        Log::debug('Failed to parse color variations data from data-variations', [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
                
                // Fallback: Extract from script text content
                $scriptText = $scriptTags->text();
                if (preg_match('/window\.__LULULEMON_COLOR_VARIATIONS__\s*=\s*({.*?});/s', $scriptText, $jsonMatches)) {
                    try {
                        $colorVariationsData = json_decode($jsonMatches[1], true);
                        if (isset($colorVariationsData[$colorTitle]) && is_array($colorVariationsData[$colorTitle])) {
                            $images = $colorVariationsData[$colorTitle];
                            Log::debug('Extracted color-specific images from script text', [
                                'color' => $colorTitle,
                                'count' => count($images),
                            ]);
                            return $images;
                        }
                    } catch (Exception $e) {
                        Log::debug('Failed to parse color variations data from script text', [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
            
            // Also try regex on full HTML as fallback
            $html = $crawler->html();
            if (preg_match('/<script[^>]*id=["\']lululemon-color-variations-data["\'][^>]*data-variations=["\']({.*?})["\']/is', $html, $matches)) {
                try {
                    $colorVariationsData = json_decode(html_entity_decode($matches[1]), true);
                    if (isset($colorVariationsData[$colorTitle]) && is_array($colorVariationsData[$colorTitle])) {
                        $images = $colorVariationsData[$colorTitle];
                        Log::debug('Extracted color-specific images from HTML regex', [
                            'color' => $colorTitle,
                            'count' => count($images),
                        ]);
                        return $images;
                    }
                } catch (Exception $e) {
                    Log::debug('Failed to parse color variations data from HTML regex', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Strategy 3: Fallback - extract from current thumbnails (may be last clicked color)
            $thumbnails = $crawler->filter(self::SELECTOR_THUMBNAILS)->first();
            if ($thumbnails->count() > 0) {
                $thumbnailLinks = $thumbnails->filter('a[href]');
                
                foreach ($thumbnailLinks as $link) {
                    $linkCrawler = new Crawler($link);
                    $href = $linkCrawler->attr('href');
                    
                    if ($href && strpos($href, 'lululemon.com/is/image') !== false) {
                        $normalizedUrl = $this->normalizeImageUrl($href);
                        if ($normalizedUrl && !in_array($normalizedUrl, $seenUrls)) {
                            $img = $linkCrawler->filter('img')->first();
                            $alt = $img->count() > 0 ? ($img->attr('alt') ?? '') : '';
                            
                            $images[] = [
                                'url' => $normalizedUrl,
                                'alt' => $alt ?: $colorTitle,
                            ];
                            $seenUrls[] = $normalizedUrl;
                        }
                    }
                    unset($linkCrawler);
                }
            }

            Log::debug('Extracted images for color (fallback)', [
                'color' => $colorTitle,
                'count' => count($images),
            ]);
        } catch (Exception $e) {
            Log::debug('Error extracting images for color', [
                'color' => $colorTitle,
                'error' => $e->getMessage(),
            ]);
        }

        return $images;
    }

    /**
     * Normalize image URL to 1600x1600 size.
     */
    private function normalizeImageUrl(string $url): ?string {
        if (empty($url)) {
            return null;
        }

        // If already absolute URL, use it
        if (preg_match('/^https?:\/\//', $url)) {
            $absoluteUrl = $url;
        } elseif (strpos($url, '//') === 0) {
            $absoluteUrl = 'https:' . $url;
        } else {
            $baseUrl     = rtrim(self::BASE_URL, '/');
            $absoluteUrl = $baseUrl . '/' . ltrim($url, '/');
        }

        // Normalize Scene7-like URLs or add size parameter
        // Check if URL already has size parameters
        if (preg_match('/[?&]size=/', $absoluteUrl)) {
            // Replace existing size parameter
            $absoluteUrl = preg_replace('/[?&]size=[^&]*/', '?size=1600,1600', $absoluteUrl);
        } elseif (preg_match('/[?&]wid=/', $absoluteUrl) || preg_match('/[?&]hei=/', $absoluteUrl)) {
            // Replace width/height parameters
            $absoluteUrl = preg_replace('/[?&](wid|hei)=[^&]*/', '', $absoluteUrl);
            $absoluteUrl .= (strpos($absoluteUrl, '?') !== false ? '&' : '?') . 'size=1600,1600';
        } else {
            // Add size parameter
            $separator = strpos($absoluteUrl, '?') !== false ? '&' : '?';
            $absoluteUrl .= $separator . 'size=1600,1600';
        }

        return $absoluteUrl;
    }

    /**
     * Extract variations from Puppeteer-injected data.
     * Puppeteer clicks each color and captures full variation data.
     *
     * @param Crawler $crawler
     * @return array Array of variations or empty array if not found
     */
    private function extractVariationsFromPuppeteerData(Crawler $crawler): array {
        $variations = [];

        try {
            // Try to get data from script tag with id lululemon-color-variations-data
            $scriptTags = $crawler->filter('script#lululemon-color-variations-data');
            if ($scriptTags->count() === 0) {
                return [];
            }

            $scriptTag = $scriptTags->first();
            
            // Try data-variations attribute first
            $dataAttr = $scriptTag->attr('data-variations');
            if ($dataAttr) {
                try {
                    $colorVariationsData = json_decode($dataAttr, true);
                    if (is_array($colorVariationsData) && !empty($colorVariationsData)) {
                        return $this->normalizePuppeteerVariations($colorVariationsData);
                    }
                } catch (Exception $e) {
                    Log::debug('Failed to parse color variations data from data-variations', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            // Fallback: Extract from script text content
            $scriptText = $scriptTag->text();
            if (preg_match('/window\.__LULULEMON_COLOR_VARIATIONS__\s*=\s*({.*?});/s', $scriptText, $jsonMatches)) {
                try {
                    $colorVariationsData = json_decode($jsonMatches[1], true);
                    if (is_array($colorVariationsData) && !empty($colorVariationsData)) {
                        return $this->normalizePuppeteerVariations($colorVariationsData);
                    }
                } catch (Exception $e) {
                    Log::debug('Failed to parse color variations data from script text', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            // Also try regex on full HTML as fallback
            $html = $crawler->html();
            if (preg_match('/<script[^>]*id=["\']lululemon-color-variations-data["\'][^>]*data-variations=["\']({.*?})["\']/is', $html, $matches)) {
                try {
                    $colorVariationsData = json_decode(html_entity_decode($matches[1]), true);
                    if (is_array($colorVariationsData) && !empty($colorVariationsData)) {
                        return $this->normalizePuppeteerVariations($colorVariationsData);
                    }
                } catch (Exception $e) {
                    Log::debug('Failed to parse color variations data from HTML regex', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (Exception $e) {
            Log::debug('Error extracting variations from Puppeteer data', [
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }

    /**
     * Normalize Puppeteer variation data to match expected structure.
     * Note: Puppeteer already filters for sale variations (only clicks buttons with sale-tag),
     * so all data here is already from sale variations.
     *
     * @param array $puppeteerData Data from Puppeteer script tag (already filtered for sale variations)
     * @return array Normalized variations array
     */
    private function normalizePuppeteerVariations(array $puppeteerData): array {
        $variations = [];

        foreach ($puppeteerData as $colorTitle => $colorData) {
            if (!is_array($colorData)) {
                continue;
            }

            // Extract data from Puppeteer structure
            $images = $colorData['images'] ?? [];
            $sizes = $colorData['sizes'] ?? ['available' => [], 'unavailable' => []];
            $price = $colorData['price'] ?? null;
            $discountPrice = $colorData['discount_price'] ?? null;
            $discountPercent = $colorData['discount_percent'] ?? null;

            // Normalize images array
            $normalizedImages = [];
            foreach ($images as $image) {
                if (is_array($image) && isset($image['url'])) {
                    $normalizedImages[] = [
                        'url' => $image['url'],
                        'alt' => $image['alt'] ?? $colorTitle,
                    ];
                } elseif (is_string($image)) {
                    $normalizedImages[] = [
                        'url' => $image,
                        'alt' => $colorTitle,
                    ];
                }
            }

            // Log price extraction for debugging
            Log::info('Normalized Puppeteer variation prices', [
                'color' => $colorTitle,
                'raw_price' => $price,
                'raw_discount_price' => $discountPrice,
                'raw_discount_percent' => $discountPercent,
                'normalized_price' => $price,
                'normalized_discount_price' => $discountPrice,
                'normalized_discount_percent' => $discountPercent,
            ]);

            $variations[] = [
                'color' => $colorTitle,
                'price' => $price,
                'discount_price' => $discountPrice,
                'discount_percent' => $discountPercent,
                'sizes' => [
                    'available' => is_array($sizes['available'] ?? null) ? $sizes['available'] : [],
                    'unavailable' => is_array($sizes['unavailable'] ?? null) ? $sizes['unavailable'] : [],
                ],
                'images' => $normalizedImages,
            ];
        }

        return $variations;
    }

    /**
     * Extract variations matrix from .color-group elements (only those with .sale-tag).
     * First tries to use data from Puppeteer (injected script tag), then falls back to DOM extraction.
     */
    private function extractVariations(Crawler $crawler): array {
        $variations = [];
        $processedColors = [];

        try {
            // Strategy 0: Try to extract from Puppeteer-injected data first
            $puppeteerData = $this->extractVariationsFromPuppeteerData($crawler);
            if (!empty($puppeteerData)) {
                Log::info('Extracted variations from Puppeteer data', [
                    'count' => count($puppeteerData),
                ]);
                return $puppeteerData;
            }
            // Strategy 1: Find all .color-group elements with sale-tag
            $colorGroups = $crawler->filter('.color-group');
            
            Log::debug('Found color groups', ['count' => $colorGroups->count()]);
            
            foreach ($colorGroups as $groupElement) {
                $groupCrawler = new Crawler($groupElement);
                
                // Check if this color-group has a sale-tag
                $saleTag = $groupCrawler->filter(self::SELECTOR_SALE_TAG)->first();
                if ($saleTag->count() === 0) {
                    // Also check parent/ancestor for sale-tag
                    try {
                        $node = $groupCrawler->getNode(0);
                        if ($node && $node->ownerDocument) {
                            $xpath = new \DOMXPath($node->ownerDocument);
                            $saleCheck = $xpath->query('ancestor-or-self::*[contains(@class, "sale-tag")]', $node);
                            if ($saleCheck->length === 0) {
                                continue; // Skip non-sale variations
                            }
                        } else {
                            continue;
                        }
                    } catch (Exception $e) {
                        continue;
                    }
                }

                // Extract color title from data-color-title (usually on button inside)
                $colorButton = $groupCrawler->filter('button[data-color-title]')->first();
                $colorTitle = null;
                
                if ($colorButton->count() > 0) {
                    $colorTitle = $colorButton->attr('data-color-title');
                } else {
                    // Try to find in the group itself or parent
                    $colorTitle = $groupCrawler->attr('data-color-title');
                    if (empty($colorTitle)) {
                        // Try parent elements
                        try {
                            $node = $groupCrawler->getNode(0);
                            if ($node && $node->ownerDocument) {
                                $xpath = new \DOMXPath($node->ownerDocument);
                                $ancestors = $xpath->query('ancestor::*[@data-color-title]', $node);
                                if ($ancestors->length > 0) {
                                    $colorTitle = $ancestors->item(0)->getAttribute('data-color-title');
                                }
                            }
                        } catch (Exception $e) {
                            // Continue
                        }
                    }
                }

                if (empty($colorTitle) || in_array($colorTitle, $processedColors)) {
                    continue;
                }

                Log::debug('Processing sale variation', ['color' => $colorTitle]);

                // Extract original price from .list-price del (in the color-group or main product area)
                $price = null;
                $listPriceDel = $groupCrawler->filter('.list-price del')->first();
                if ($listPriceDel->count() === 0) {
                    // Try in main product area
                    $listPriceDel = $crawler->filter('.list-price del')->first();
                }
                if ($listPriceDel->count() > 0) {
                    $priceText = trim($listPriceDel->text());
                    // Remove € symbol and extract number
                    if (preg_match('/(?:€|EUR|EUR\s*)?([\d,]+\.?\d*)/', $priceText, $matches)) {
                        $price = (float)str_replace(',', '', $matches[1]);
                    }
                }

                // Extract discount price from .markdown-prices
                $discountPrice = null;
                $markdownPrices = $groupCrawler->filter('.markdown-prices')->first();
                if ($markdownPrices->count() === 0) {
                    // Try in main product area
                    $markdownPrices = $crawler->filter('.markdown-prices')->first();
                }
                if ($markdownPrices->count() > 0) {
                    $discountText = trim($markdownPrices->text());
                    // Remove € symbol and extract number
                    if (preg_match('/(?:€|EUR|EUR\s*)?([\d,]+\.?\d*)/', $discountText, $matches)) {
                        $discountPrice = (float)str_replace(',', '', $matches[1]);
                    }
                }

                // Calculate discount percent: Math.round(100 - (discount_price / price) * 100)
                $discountPercent = null;
                if ($price !== null && $price > 0 && $discountPrice !== null) {
                    $discountPercent = (int)round(100 - ($discountPrice / $price) * 100);
                }

                // Extract sizes from input.options-select elements (in main product area)
                $sizes = $this->extractSizesFromInputs($crawler);

                // Extract images for this specific color variation
                $colorImages = $this->extractImagesForColor($crawler, $colorTitle, $colorButton);

                // Structure according to requirements
                $variation = [
                    'color' => $colorTitle,
                    'price' => $price,
                    'discount_price' => $discountPrice,
                    'discount_percent' => $discountPercent,
                    'sizes' => [
                        'available' => $sizes['available'] ?? [],
                        'unavailable' => $sizes['unavailable'] ?? [],
                    ],
                    'images' => $colorImages,
                ];

                $variations[] = $variation;
                $processedColors[] = $colorTitle;

                Log::debug('Added variation', [
                    'color' => $colorTitle,
                    'price' => $price,
                    'discount_price' => $discountPrice,
                    'discount_percent' => $discountPercent,
                    'available_sizes' => count($sizes['available'] ?? []),
                    'unavailable_sizes' => count($sizes['unavailable'] ?? []),
                    'images_count' => count($colorImages),
                ]);
            }

            // Strategy 2: If no color-groups found, try finding sale-tag buttons directly
            if (empty($variations)) {
                $saleButtons = $crawler->filter('button[data-color-title]');
                foreach ($saleButtons as $button) {
                    $buttonCrawler = new Crawler($button);
                    
                    // Check for sale-tag
                    $saleTag = $buttonCrawler->filter(self::SELECTOR_SALE_TAG)->first();
                    if ($saleTag->count() === 0) {
                        try {
                            $node = $buttonCrawler->getNode(0);
                            if ($node && $node->ownerDocument) {
                                $xpath = new \DOMXPath($node->ownerDocument);
                                $saleCheck = $xpath->query('ancestor-or-self::*[contains(@class, "sale-tag")]', $node);
                                if ($saleCheck->length === 0) {
                                    continue;
                                }
                            } else {
                                continue;
                            }
                        } catch (Exception $e) {
                            continue;
                        }
                    }

                    $colorTitle = $buttonCrawler->attr('data-color-title');
                    if (empty($colorTitle) || in_array($colorTitle, $processedColors)) {
                        continue;
                    }

                    // Extract prices from main product area
                    $price = null;
                    $listPriceDel = $crawler->filter('.list-price del')->first();
                    if ($listPriceDel->count() > 0) {
                        $priceText = trim($listPriceDel->text());
                        if (preg_match('/(?:€|EUR|EUR\s*)?([\d,]+\.?\d*)/', $priceText, $matches)) {
                            $price = (float)str_replace(',', '', $matches[1]);
                        }
                    }

                    $discountPrice = null;
                    $markdownPrices = $crawler->filter('.markdown-prices')->first();
                    if ($markdownPrices->count() > 0) {
                        $discountText = trim($markdownPrices->text());
                        if (preg_match('/(?:€|EUR|EUR\s*)?([\d,]+\.?\d*)/', $discountText, $matches)) {
                            $discountPrice = (float)str_replace(',', '', $matches[1]);
                        }
                    }

                    $discountPercent = null;
                    if ($price !== null && $price > 0 && $discountPrice !== null) {
                        $discountPercent = (int)round(100 - ($discountPrice / $price) * 100);
                    }

                    $sizes = $this->extractSizesFromInputs($crawler);

                    // Extract images for this specific color variation
                    $colorImages = $this->extractImagesForColor($crawler, $colorTitle, $buttonCrawler);

                    $variations[] = [
                        'color' => $colorTitle,
                        'price' => $price,
                        'discount_price' => $discountPrice,
                        'discount_percent' => $discountPercent,
                        'sizes' => [
                            'available' => $sizes['available'] ?? [],
                            'unavailable' => $sizes['unavailable'] ?? [],
                        ],
                        'images' => $colorImages,
                    ];

                    $processedColors[] = $colorTitle;
                }
            }

            $saleCount = count($variations);
            Log::info('Extracted variations matrix', [
                'total_variations' => $saleCount,
                'colors' => $processedColors,
            ]);
        } catch (Exception $e) {
            Log::error('Error extracting variations', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return $variations;
    }


    /**
     * Extract sizes from input.options-select elements.
     * Returns array with 'available' and 'unavailable' keys.
     */
    private function extractSizesFromInputs(Crawler $container): array {
        $available = [];
        $unavailable = [];

        try {
            // Find all input.options-select elements
            $sizeInputs = $container->filter('input.options-select');
            
            Log::debug('Found size inputs', ['count' => $sizeInputs->count()]);
            
            foreach ($sizeInputs as $input) {
                $inputCrawler = new Crawler($input);
                
                // Extract size from data-attr-value (primary source)
                $size = $inputCrawler->attr('data-attr-value');
                
                if (empty($size)) {
                    // Fallback to id, value attribute, or data-attr-hybridsize
                    $size = $inputCrawler->attr('id') ?? 
                           $inputCrawler->attr('data-attr-hybridsize') ?? 
                           $inputCrawler->attr('value');
                }
                
                if (empty($size)) {
                    continue;
                }

                // Check if disabled (unavailable) - check class attribute string
                $classAttr = $inputCrawler->attr('class') ?? '';
                $hasDisabledClass = strpos($classAttr, 'disabled') !== false;
                $isDisabled = $hasDisabledClass || 
                             $inputCrawler->attr('disabled') !== null || 
                             $inputCrawler->attr('aria-disabled') === 'true' ||
                             strpos(strtolower($inputCrawler->attr('aria-label') ?? ''), 'sold out') !== false;

                if ($isDisabled) {
                    $unavailable[] = $size;
                } else {
                    $available[] = $size;
                }
                
                unset($inputCrawler);
            }
            
            // If no sizes found with options-select, try alternative selectors
            if (empty($available) && empty($unavailable)) {
                $altInputs = $container->filter('input[type="radio"][data-attr-value], input[data-size], .size-selector input, input[name*="size"]');
                foreach ($altInputs as $input) {
                    $inputCrawler = new Crawler($input);
                    $size = $inputCrawler->attr('data-attr-value') ?? 
                           $inputCrawler->attr('data-attr-hybridsize') ??
                           $inputCrawler->attr('id') ??
                           $inputCrawler->attr('data-size') ?? 
                           $inputCrawler->attr('value');
                    
                    if (!empty($size)) {
                        $classAttr = $inputCrawler->attr('class') ?? '';
                        $hasDisabledClass = strpos($classAttr, 'disabled') !== false;
                        $isDisabled = $hasDisabledClass || 
                                     $inputCrawler->attr('disabled') !== null ||
                                     strpos(strtolower($inputCrawler->attr('aria-label') ?? ''), 'sold out') !== false;
                        if ($isDisabled) {
                            $unavailable[] = $size;
                        } else {
                            $available[] = $size;
                        }
                    }
                    unset($inputCrawler);
                }
            }
        } catch (Exception $e) {
            Log::debug('Error extracting sizes from inputs', ['error' => $e->getMessage()]);
        }

        return [
            'available' => array_unique($available),
            'unavailable' => array_unique($unavailable),
        ];
    }

    /**
     * Extract price data from variations (lowest discount price or price).
     */
    private function extractPriceFromVariations(array $variations): array {
        $result = [
            'price'         => null,
            'currency'      => 'EUR',
            'originalPrice' => null,
        ];

        if (empty($variations)) {
            return $result;
        }

        // Find lowest discount price and lowest original price
        $lowestDiscountPrice = null;
        $lowestOriginalPrice = null;

        foreach ($variations as $variation) {
            $discountPrice = $variation['discount_price'] ?? null;
            $originalPrice = $variation['price'] ?? null;

            if ($discountPrice !== null) {
                if ($lowestDiscountPrice === null || $discountPrice < $lowestDiscountPrice) {
                    $lowestDiscountPrice = $discountPrice;
                }
            }

            if ($originalPrice !== null) {
                if ($lowestOriginalPrice === null || $originalPrice < $lowestOriginalPrice) {
                    $lowestOriginalPrice = $originalPrice;
                }
            }
        }

        $result['price'] = $lowestDiscountPrice ?? $lowestOriginalPrice;
        $result['originalPrice'] = $lowestOriginalPrice;

        return $result;
    }

    /**
     * Calculate stock quantity from variations.
     */
    private function calculateStockQuantity(array $variations): int {
        $totalStock = 0;

        foreach ($variations as $variation) {
            $sizes = $variation['sizes'] ?? [];
            $availableSizes = $sizes['available'] ?? [];
            $totalStock += count($availableSizes);
        }

        return $totalStock;
    }

    /**
     * Calculate product status from variations.
     */
    private function calculateStatus(array $variations): string {
        if (empty($variations)) {
            return 'out_of_stock';
        }

        // Check if any variation has available sizes
        foreach ($variations as $variation) {
            $sizes = $variation['sizes'] ?? [];
            $availableSizes = $sizes['available'] ?? [];
            if (!empty($availableSizes)) {
                return 'published';
            }
        }

        return 'out_of_stock';
    }

    /**
     * Transform Lululemon variations format to Calvin Klein format for WordpressSyncService compatibility.
     * 
     * Lululemon format:
     * {
     *   "color": "Rainforest Green",
     *   "price": 108,
     *   "discount_price": 79,
     *   "sizes": { "available": ["XS", "S"], "unavailable": ["L"] },
     *   "images": [...],
     *   "images_local": [...]
     * }
     * 
     * Calvin Klein format (expected by WordpressSyncService):
     * {
     *   "colour_label": "Rainforest Green",
     *   "base_price": 108,
     *   "variants": [
     *     { "size": "XS", "stock_availability": true, "price": 79 },
     *     { "size": "S", "stock_availability": true, "price": 79 }
     *   ],
     *   "images_local": [...]
     * }
     *
     * @param array $variations Lululemon format variations
     * @return array Calvin Klein format variant matrix
     */
    private function transformVariationsToCalvinKleinFormat(array $variations): array {
        $variantMatrix = [];

        foreach ($variations as $variation) {
            $colorLabel = $variation['color'] ?? null;
            if (!$colorLabel) {
                continue;
            }

            $sizes = $variation['sizes'] ?? [];
            $availableSizes = $sizes['available'] ?? [];
            $unavailableSizes = $sizes['unavailable'] ?? [];
            $discountPrice = $variation['discount_price'] ?? $variation['price'] ?? null;
            $originalPrice = $variation['price'] ?? null;

            // Build variants array (one per size)
            $variants = [];
            
            // Add available sizes
            foreach ($availableSizes as $size) {
                $variants[] = [
                    'size' => $size,
                    'stock_availability' => true,
                    'price' => $discountPrice, // Use discount price for sale variations
                ];
            }
            
            // Add unavailable sizes
            foreach ($unavailableSizes as $size) {
                $variants[] = [
                    'size' => $size,
                    'stock_availability' => false,
                    'price' => $discountPrice,
                ];
            }

            // Build colourway in Calvin Klein format
            $colourway = [
                'colour_label' => $colorLabel,
                'colour' => $colorLabel, // Also include for compatibility
                'base_price' => $originalPrice,
                'variants' => $variants,
                'images_local' => $variation['images_local'] ?? [],
            ];

            // Add discount info if available
            if (isset($variation['discount_price'])) {
                $colourway['discount_price'] = $variation['discount_price'];
            }
            if (isset($variation['discount_percent'])) {
                $colourway['discount_percent'] = $variation['discount_percent'];
            }

            $variantMatrix[] = $colourway;
        }

        return $variantMatrix;
    }

    /**
     * Enrich variant matrix with local image paths for each colourway.
     * Similar to Calvin Klein's enrichVariantMatrixWithLocalImages.
     *
     * @param array   $variations The variant matrix with colour variants
     * @param Product $product    Product model
     * @param string  $slug       Product slug
     *
     * @return array Enriched variant matrix with images_local key for each colourway
     */
    private function enrichVariantMatrixWithLocalImages(array $variations, Product $product, string $slug): array {
        foreach ($variations as $i => $variation) {
            $variationImages = $variation['images'] ?? [];
            $downloadedPaths = [];

            if (!empty($variationImages) && is_array($variationImages)) {
                $colorTitle = $variation['color'] ?? 'default';
                $colorSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $colorTitle));
                $colorSlug = trim($colorSlug, '-');
                if (empty($colorSlug)) {
                    $colorSlug = 'default';
                }

                foreach ($variationImages as $imageIndex => $image) {
                    // Extract URL - handle both array and string formats
                    $imageUrl = null;
                    if (is_array($image) && isset($image['url'])) {
                        $imageUrl = $image['url'];
                    } elseif (is_string($image)) {
                        $imageUrl = $image;
                    }

                    if (empty($imageUrl)) {
                        continue;
                    }

                    // Download and save image
                    $localPath = $this->downloadAndSaveVariantImage($imageUrl, $product, $slug, $colorSlug, $imageIndex);

                    if ($localPath) {
                        $downloadedPaths[] = $localPath;
                    }
                }
            }

            // Always set images_local key (even if empty array)
            $variations[$i]['images_local'] = $downloadedPaths;
        }

        return $variations;
    }

    /**
     * Download and save variant image from URL with colour slug in filename to avoid collisions.
     * Similar to Calvin Klein's downloadAndSaveVariantImage.
     *
     * @param string  $imageUrl   Original image URL
     * @param Product $product    Product model
     * @param string  $slug       Product slug
     * @param string  $colorSlug  Colour slug for filename uniqueness
     * @param int     $imageIndex Image index for variant identification
     *
     * @return string|null Relative path to saved image or null on failure
     */
    private function downloadAndSaveVariantImage(string $imageUrl, Product $product, string $slug, string $colorSlug, int $imageIndex): ?string {
        try {
            // Download image with increased timeout for large images
            $response = Http::timeout(60)
                ->connectTimeout(30)
                ->withHeaders([
                    'Accept'     => 'image/*',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                ])
                ->get($imageUrl);

            if (!$response->successful()) {
                Log::warning('Failed to download variant image', [
                    'product_id'  => $product->id,
                    'url'        => $imageUrl,
                    'status'     => $response->status(),
                    'colour_slug' => $colorSlug,
                ]);

                return null;
            }

            // Always use JPG extension (Lululemon images are typically JPG)
            $extension = 'jpg';

            // Generate filename: lululemon_{product_id}_{slug}_{colorSlug}_{imageIndex}_{timestamp}.jpg
            $timestamp = time();
            $filename  = $this->generateVariantImageFilename($product->id, $slug, $colorSlug, $imageIndex, $timestamp, $extension);

            // Ensure lululemon directory exists
            $lululemonDir = 'images/lululemon';
            if (!Storage::disk('public')->exists($lululemonDir)) {
                Storage::disk('public')->makeDirectory($lululemonDir);
            }

            // Save to storage/app/public/images/lululemon/
            $path = "{$lululemonDir}/{$filename}";
            Storage::disk('public')->put($path, $response->body());

            // Return relative path for images_local
            return $path;
        } catch (Exception $e) {
            Log::error('Error downloading variant image', [
                'product_id'  => $product->id,
                'url'        => $imageUrl,
                'error'      => $e->getMessage(),
                'colour_slug' => $colorSlug,
            ]);

            return null;
        }
    }

    /**
     * Generate variant image filename following pattern: lululemon_{product_id}_{slug}_{colorSlug}_{imageIndex}_{timestamp}.jpg
     *
     * @param int    $productId  Product ID
     * @param string $slug       Product slug
     * @param string $colorSlug  Colour slug for uniqueness
     * @param int    $imageIndex Image index
     * @param int    $timestamp  Timestamp
     * @param string $extension  File extension (default: 'jpg')
     *
     * @return string Generated filename
     */
    private function generateVariantImageFilename(int $productId, string $slug, string $colorSlug, int $imageIndex, int $timestamp, string $extension = 'jpg'): string {
        // Sanitize slug
        $cleanSlug = Str::slug($slug);
        if (empty($cleanSlug)) {
            $cleanSlug = 'product';
        }

        // Sanitize colour slug
        $cleanColourSlug = Str::slug($colorSlug);
        if (empty($cleanColourSlug)) {
            $cleanColourSlug = 'default';
        }

        return "lululemon_{$productId}_{$cleanSlug}_{$cleanColourSlug}_{$imageIndex}_{$timestamp}.{$extension}";
    }

    /**
     * Download and save image from URL.
     * Similar to Calvin Klein's downloadAndSaveImage.
     *
     * @param string  $imageUrl   Original image URL
     * @param Product $product    Product model
     * @param string  $slug       Product slug
     * @param int     $imageIndex Image index for variant identification
     *
     * @return string|null Relative path to saved image or null on failure
     */
    private function downloadAndSaveImage(string $imageUrl, Product $product, string $slug, int $imageIndex): ?string {
        try {
            // Download image with increased timeout for large images
            $response = Http::timeout(60)
                ->connectTimeout(30)
                ->withHeaders([
                    'Accept'     => 'image/*',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                ])
                ->get($imageUrl);

            if (!$response->successful()) {
                Log::warning('Failed to download image', [
                    'product_id' => $product->id,
                    'url'        => $imageUrl,
                    'status'     => $response->status(),
                ]);

                return null;
            }

            // Always use JPG extension (Lululemon images are typically JPG)
            $extension = 'jpg';

            // Generate filename: lululemon_{product_id}_{slug}_{variant}_{timestamp}.jpg
            $variant     = $imageIndex > 0 ? $imageIndex : '';
            $timestamp   = time();
            $filename    = $this->generateImageFilename($product->id, $slug, $variant, $timestamp, $extension);

            // Ensure lululemon directory exists
            $lululemonDir = 'images/lululemon';
            if (!Storage::disk('public')->exists($lululemonDir)) {
                Storage::disk('public')->makeDirectory($lululemonDir);
            }

            // Save to storage/app/public/images/lululemon/
            $path = "{$lululemonDir}/{$filename}";
            Storage::disk('public')->put($path, $response->body());

            // Return relative path for source_url
            return $path;
        } catch (Exception $e) {
            Log::error('Error downloading image', [
                'product_id' => $product->id,
                'url'       => $imageUrl,
                'error'     => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Generate image filename following pattern: lululemon_{product_id}_{slug}_{variant}_{timestamp}.jpg
     *
     * @param int    $productId Product ID
     * @param string $slug      Product slug
     * @param mixed  $variant   Variant identifier (index or hash)
     * @param int    $timestamp Timestamp
     * @param string $extension File extension (default: 'jpg')
     *
     * @return string Generated filename
     */
    private function generateImageFilename(int $productId, string $slug, $variant, int $timestamp, string $extension = 'jpg'): string {
        // Sanitize slug
        $cleanSlug = Str::slug($slug);
        if (empty($cleanSlug)) {
            $cleanSlug = 'product';
        }

        // Build variant part
        $variantPart = '';
        if ($variant !== '' && $variant !== null) {
            $variantPart = '_' . (is_string($variant) ? Str::slug($variant) : (string) $variant);
        }

        return "lululemon_{$productId}_{$cleanSlug}{$variantPart}_{$timestamp}.{$extension}";
    }
}
