<?php

declare(strict_types=1);

namespace App\Services\Scrapers\TommyHilfiger;

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

class TommyHilfigerProductDetailScraper {
    private const BASE_URL                  = 'https://nl.tommy.com';
    private const DEFAULT_CONCURRENCY       = 20;
    private const DEFAULT_BATCH_SIZE        = 200;
    private const CURL_TIMEOUT              = 60;
    private const CURL_CONNECT_TIMEOUT      = 30;
    private const GC_COLLECT_INTERVAL       = 5;
    private const CURL_MULTI_SELECT_TIMEOUT = 0.1;
    private const MAX_RETRIES               = 5;
    private const RETRY_DELAY_BASE          = 2;
    private const VARIANT_PAGE_CONCURRENCY  = 8;

    private const SELECTOR_PRODUCT_NAME        = 'h1[data-testid="ProductHeader-ProductName-typography-h1"]';
    private const SELECTOR_DESCRIPTION_SECTION = 'section#description';
    private const SELECTOR_DESCRIPTION_TEXT    = 'div[data-testid="typography-div"]';
    private const SELECTOR_STYLE_NUMBER        = 'div.ProductAccordions_styleNumber__uzRY1';
    private const SELECTOR_PRICE               = 'span[data-testid*="ProductHeaderPrice-PriceText"]';
    private const SELECTOR_CAROUSEL_ITEM       = 'div[data-testid="CarouselItemWrapper"]';
    private const SELECTOR_PRODUCT_IMAGE       = 'img[data-testid="prod-mainImage_img"]';
    private const SELECTOR_ADD_TO_BAG          = 'button[data-testid*="AddToBag"], button[data-testid*="add-to-bag"]';
    private const SELECTOR_OUT_OF_STOCK        = '[data-testid*="out-of-stock"], [data-testid*="OutOfStock"]';

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

        Log::info('Starting product detail scraping', [
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
        Log::info('Product detail scraping completed', [
            'total_processed' => $processed,
            'successful'      => $successful,
            'failed'          => $failed,
            'total_duration'  => round($totalDuration, 2) . 's',
            'average_rate'    => $processed > 0 ? round($processed / $totalDuration, 2) . ' products/s' : '0 products/s',
        ]);
    }

    /**
     * Fetch a batch of URLs using multi-cURL and process them immediately to minimize memory usage.
     */
    private function fetchAndProcessBatch (array $urls, int &$processed, int &$successful, int &$failed) : void {
        $multiHandle = curl_multi_init();
        $handles     = [];

        foreach ( $urls as $url ) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => self::CURL_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::CURL_CONNECT_TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_ENCODING       => 'gzip, deflate, br',
                CURLOPT_TCP_KEEPALIVE  => 1,
                CURLOPT_TCP_KEEPIDLE   => 300,
                CURLOPT_TCP_KEEPINTVL  => 300,
                CURLOPT_HTTPHEADER     => [
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
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
            $handles[$url] = $ch;
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

        $retryUrls = [];

        foreach ( $handles as $url => $ch ) {
            $processed++;
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $error    = curl_error($ch);

            if ( $error ) {
                $retryUrls[] = ['url'      => $url,
                                'finalUrl' => $finalUrl,
                                'httpCode' => null,
                                'error'    => $error,
                                'attempt'  => 1
                ];
            } elseif ( $httpCode >= 200 && $httpCode < 300 ) {
                $html = curl_multi_getcontent($ch);

                try {
                    $this->parseAndSaveProduct($url, $html, $finalUrl);
                    $successful++;
                } catch ( Exception $e ) {
                    $failed++;
                    Log::error('Error parsing/saving product', [
                        'url'   => $url,
                        'error' => $e->getMessage(),
                    ]);
                }

                unset($html);
            } else {
                $isRetryable = in_array($httpCode, [429, 502, 503, 504, 520, 521, 522, 523, 524]);
                if ( $isRetryable ) {
                    $retryUrls[] = ['url'      => $url,
                                    'finalUrl' => $finalUrl,
                                    'httpCode' => $httpCode,
                                    'error'    => "HTTP {$httpCode}",
                                    'attempt'  => 1
                    ];
                } else {
                    $failed++;
                    Log::warning('Failed to fetch product page', [
                        'url'         => $url,
                        'error'       => "HTTP {$httpCode}",
                        'status_code' => $httpCode,
                    ]);
                }
            }

            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }

        curl_multi_close($multiHandle);
        unset($handles, $multiHandle);

        if ( !empty($retryUrls) ) {
            $this->retryFailedUrls($retryUrls, $processed, $successful, $failed);
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
        $externalId   = $this->extractExternalId($crawler);
        $priceData    = $this->extractPrice($crawler);
        $availability = $this->extractAvailability($crawler);
        $images       = $this->extractImages($crawler);
        $attributes   = $this->extractAttributes($crawler, $priceData, $availability);

        // Translate description to Persian using Gemini AI
        $descriptionTranslated = null;
        if (!empty($description)) {
            try {
                $geminiFunctions = new GeminiFunctions();
                $descriptionTranslated = $geminiFunctions->translateToPersian($description);
            } catch (\Exception $e) {
                Log::warning('Failed to translate product description to Persian', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                    'description_preview' => substr($description, 0, 100) . '...',
                ]);
                // Fallback: leave description_translated as null
            }
        }

        // Extract variant matrix from __NEXT_DATA__ JSON
        $variantMatrix = $this->extractVariantMatrixFromNextData($html, $url);

        unset($crawler);

        // Fetch variant PDP pages and extract their images
        if ( !empty($variantMatrix) ) {
            $variantMatrix = $this->fetchAndAttachVariantImages($variantMatrix, $finalUrl, $url, $images);
        }

        $rawData = [
            'url'            => $url,
            'final_url'      => $finalUrl,
            'title'          => $title,
            'description'    => $description,
            'external_id'    => $externalId,
            'price'          => $priceData['price'],
            'currency'       => $priceData['currency'],
            'original_price' => $priceData['originalPrice'] ?? null,
            'availability'   => $availability,
            'images'         => $images,
            'attributes'     => $attributes,
        ];

        // Add variant matrix to raw_data if available
        if ( !empty($variantMatrix) ) {
            $rawData['variants'] = $variantMatrix;

            // Merge variant images into main images array (with deduplication)
            $allImages         = $this->mergeVariantImages($images, $variantMatrix);
            $images            = $allImages;
            $rawData['images'] = $allImages;
        }

        unset($html);

        // Build meta array with translated description and other metadata
        $meta = [
            'brand' => 'Tommy Hilfiger',
            'discount' => 40,
        ];
        
        if ($descriptionTranslated !== null) {
            $meta['description_translated'] = $descriptionTranslated;
        }

        DB::transaction(function () use ($slug, $externalId, $title, $description, $priceData, $availability, $images, $attributes, $rawData, $variantMatrix, $meta) {
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
                    'stock_quantity' => 1,
                    'status'         => $availability['status'],
                    'raw_data'       => $rawData,
                    'meta'           => $meta,
                ]);

            $product->media()->delete();
            $product->attributes()->delete();

            if ( !empty($images) ) {
                $mediaData = [];
                $isFirst   = true;
                foreach ( $images as $imageIndex => $image ) {
                    $imageUrl = $image['url'] ?? '';
                    if ( empty($imageUrl) ) {
                        continue;
                    }

                    // Download and save image
                    $localPath = $this->downloadAndSaveImage($imageUrl, $product, $slug, $imageIndex);

                    if ( $localPath ) {
                        $mediaData[] = [
                            'product_id' => $product->id,
                            'type'       => 'image',
                            'source_url' => $localPath, // Store relative path instead of Scene7 URL
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

            if ( !empty($attributes) ) {
                $attributeData = [];
                foreach ( $attributes as $name => $value ) {
                    $attributeData[] = [
                        'product_id' => $product->id,
                        'name'       => $name,
                        'value'      => $value,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                ProductAttribute::insert($attributeData);
                unset($attributeData);
            }

            // Save variant matrix attributes if available
            if ( !empty($variantMatrix) ) {
                // Enrich variant matrix with images_local before saving
                $variantMatrix = $this->enrichVariantMatrixWithLocalImages($variantMatrix, $product, $slug);

                $variantAttributeData = [];

                // 1. variant_matrix - full JSON
                $variantAttributeData[] = [
                    'product_id' => $product->id,
                    'name'       => 'variant_matrix',
                    'value'      => json_encode($variantMatrix, JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                // 2. available_colours - JSON array of colours with stock
                $availableColours = collect($variantMatrix)->filter(function ($colour) {
                        return collect($colour['variants'] ?? [])->contains(fn($v) => !empty($v['stock_availability']));
                    })->pluck('colour_label')->unique()->values()->all();

                if ( !empty($availableColours) ) {
                    $variantAttributeData[] = [
                        'product_id' => $product->id,
                        'name'       => 'available_colours',
                        'value'      => json_encode($availableColours, JSON_UNESCAPED_UNICODE),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                // 3. available_sizes_by_colour - compact size availability map
                $sizeMap = [];
                foreach ( $variantMatrix as $colourRow ) {
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
                    $variantAttributeData[] = [
                        'product_id' => $product->id,
                        'name'       => 'available_sizes_by_colour',
                        'value'      => json_encode($sizeMap, JSON_UNESCAPED_UNICODE),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if ( !empty($variantAttributeData) ) {
                    ProductAttribute::insert($variantAttributeData);
                    unset($variantAttributeData);
                }
            }
        });

        unset($rawData, $images, $attributes, $variantMatrix);
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
            $element = $crawler->filter(self::SELECTOR_PRODUCT_NAME)->first();
            if ( $element->count() > 0 ) {
                return trim($element->text());
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
            $section = $crawler->filter(self::SELECTOR_DESCRIPTION_SECTION)->first();
            if ( $section->count() > 0 ) {
                $textDiv = $section->filter(self::SELECTOR_DESCRIPTION_TEXT)->first();
                if ( $textDiv->count() > 0 ) {
                    $html = $textDiv->html();
                    $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
                    $text = strip_tags($html);
                    $text = preg_replace('/\s+/', ' ', $text);
                    $text = trim($text);

                    return $text ?: null;
                }
            }
        } catch ( Exception $e ) {
            Log::debug('Error extracting description', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Extract external ID (style number).
     */
    private function extractExternalId (Crawler $crawler) : ?string {
        try {
            $section = $crawler->filter(self::SELECTOR_DESCRIPTION_SECTION)->first();
            if ( $section->count() > 0 ) {
                $styleDiv = $section->filter(self::SELECTOR_STYLE_NUMBER)->first();
                if ( $styleDiv->count() > 0 ) {
                    return trim($styleDiv->text());
                }
            }
        } catch ( Exception $e ) {
            Log::debug('Error extracting external ID', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Extract price and currency.
     */
    private function extractPrice (Crawler $crawler) : array {
        $result = [
            'price'         => null,
            'currency'      => null,
            'originalPrice' => null,
        ];

        try {
            $priceElements = $crawler->filter(self::SELECTOR_PRICE);

            if ( $priceElements->count() > 0 ) {
                $priceText = trim($priceElements->last()->text());

                if ( preg_match('/€\s*([\d,]+\.?\d*)/', $priceText, $matches) ) {
                    $result['currency'] = 'EUR';
                    $amount             = str_replace(',', '', $matches[1]);
                    $result['price']    = (float) $amount;
                } elseif ( preg_match('/([\d,]+\.?\d*)\s*€/', $priceText, $matches) ) {
                    $result['currency'] = 'EUR';
                    $amount             = str_replace(',', '', $matches[1]);
                    $result['price']    = (float) $amount;
                }

                if ( $priceElements->count() > 1 ) {
                    $originalPriceText = trim($priceElements->first()->text());
                    if ( preg_match('/€\s*([\d,]+\.?\d*)/', $originalPriceText, $matches) ) {
                        $amount                  = str_replace(',', '', $matches[1]);
                        $result['originalPrice'] = (float) $amount;
                    } elseif ( preg_match('/([\d,]+\.?\d*)\s*€/', $originalPriceText, $matches) ) {
                        $amount                  = str_replace(',', '', $matches[1]);
                        $result['originalPrice'] = (float) $amount;
                    }
                }
            }
        } catch ( Exception $e ) {
            Log::debug('Error extracting price', ['error' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * Extract availability information.
     */
    private function extractAvailability (Crawler $crawler) : array {
        $result = [
            'stockQuantity' => null,
            'status'        => 'published',
            'message'       => null,
        ];

        try {
            $outOfStockElements = $crawler->filter(self::SELECTOR_OUT_OF_STOCK);
            if ( $outOfStockElements->count() > 0 ) {
                $result['stockQuantity'] = 0;
                $result['status']        = 'out_of_stock';
                $result['message']       = trim($outOfStockElements->first()->text());

                return $result;
            }

            $addToBagButtons = $crawler->filter(self::SELECTOR_ADD_TO_BAG);
            if ( $addToBagButtons->count() > 0 ) {
                $button   = $addToBagButtons->first();
                $disabled = $button->attr('disabled') !== null || $button->attr('aria-disabled') === 'true' || str_contains(strtolower($button->text()), 'out of stock') || str_contains(strtolower($button->text()), 'sold out');

                if ( $disabled ) {
                    $result['stockQuantity'] = 0;
                    $result['status']        = 'out_of_stock';
                    $result['message']       = trim($button->text());

                    return $result;
                }
            }

            $result['status'] = 'published';
        } catch ( Exception $e ) {
            Log::debug('Error extracting availability', ['error' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * Extract product images.
     */
    private function extractImages (Crawler $crawler) : array {
        $images   = [];
        $seenUrls = [];

        try {
            $carouselItems = $crawler->filter(self::SELECTOR_CAROUSEL_ITEM);

            foreach ( $carouselItems as $item ) {
                $itemCrawler = new Crawler($item);

                $img = $itemCrawler->filter(self::SELECTOR_PRODUCT_IMAGE)->first();

                if ( $img->count() === 0 ) {
                    $img = $itemCrawler->filter('picture img')->first();
                }

                if ( $img->count() === 0 ) {
                    $img = $itemCrawler->filter('img')->first();
                }

                if ( $img->count() > 0 ) {
                    $src = $img->attr('src');
                    $alt = $img->attr('alt');

                    if ( $src ) {
                        $absoluteUrl = $this->normalizeImageUrl($src);

                        if ( $absoluteUrl && !in_array($absoluteUrl, $seenUrls) ) {
                            $images[]   = [
                                'url' => $absoluteUrl,
                                'alt' => $alt,
                            ];
                            $seenUrls[] = $absoluteUrl;
                        }
                    }
                }
                unset($itemCrawler);
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
     * Extract additional product attributes.
     */
    private function extractAttributes (Crawler $crawler, array $priceData, array $availability) : array {
        $attributes = [];

        if ( isset($priceData['originalPrice']) && $priceData['originalPrice'] !== $priceData['price'] ) {
            $attributes['original_price'] = (string) $priceData['originalPrice'];
        }

        if ( !empty($availability['message']) ) {
            $attributes['availability_message'] = $availability['message'];
        }

        try {
            $accordionSections = $crawler->filter('section[data-testid*="accordion"]');

            foreach ( $accordionSections as $section ) {
                $sectionCrawler = new Crawler($section);
                $sectionId      = $section->getAttribute('id') ?? '';

                $content = $sectionCrawler->filter('div[data-testid="typography-div"]')->first();
                if ( $content->count() > 0 ) {
                    $text = trim($content->text());
                    if ( $text ) {
                        $attributeName = str_replace('-', '_', $sectionId);
                        if ( $attributeName && $attributeName !== 'description' ) {
                            $attributes[$attributeName] = $text;
                        }
                    }
                }
                unset($sectionCrawler);
            }
        } catch ( Exception $e ) {
            Log::debug('Error extracting additional attributes', ['error' => $e->getMessage()]);
        }

        return $attributes;
    }

    /**
     * Extract variant matrix from __NEXT_DATA__ JSON script tag.
     *
     * @param string $html The HTML content of the product page
     * @param string $url  The product URL for logging purposes
     *
     * @return array Normalized variant matrix array, or empty array on failure
     */
    private function extractVariantMatrixFromNextData (string $html, string $url) : array {
        try {
            // Find the __NEXT_DATA__ script tag
            if ( !preg_match('/<script[^>]*id=["\']__NEXT_DATA__["\'][^>]*type=["\']application\/json["\'][^>]*>(.*?)<\/script>/is', $html, $matches) ) {
                Log::debug('__NEXT_DATA__ script tag not found', ['url' => $url]);

                return [];
            }

            $jsonString = $matches[1];
            $nextData   = json_decode($jsonString, true);

            if ( json_last_error() !== JSON_ERROR_NONE ) {
                Log::warning('Failed to decode __NEXT_DATA__ JSON', [
                    'url'        => $url,
                    'json_error' => json_last_error_msg(),
                ]);

                return [];
            }

            // Try to find colourways/variants structure
            $colourways = $this->findColourwaysInNextData($nextData);

            // Also look for the current product object (which might be structured differently)
            $currentProduct = $this->findCurrentProductInNextData($nextData);
            if ( $currentProduct !== null ) {
                // Check if variants have colour field (flat structure that needs grouping)
                $variants            = $currentProduct['variants'] ?? [];
                $hasColourInVariants = !empty($variants) && isset($variants[0]['colour']);

                if ( $hasColourInVariants ) {
                    // Group variants by colour to create colourways
                    $groupedColourways = $this->groupVariantsByColour($currentProduct);
                    foreach ( $groupedColourways as $groupedColourway ) {
                        // Check if this colourway is already in colourways
                        $colourLabel     = $groupedColourway['label'] ?? $groupedColourway['colour'] ?? null;
                        $alreadyIncluded = false;

                        if ( $colourLabel ) {
                            foreach ( $colourways as $existingColourway ) {
                                $existingLabel = $existingColourway['label'] ?? $existingColourway['colour'] ?? null;
                                if ( $existingLabel === $colourLabel ) {
                                    $alreadyIncluded = true;
                                    break;
                                }
                            }
                        }

                        if ( !$alreadyIncluded ) {
                            $colourways[] = $groupedColourway;
                        }
                    }
                } else {
                    // Current product is already a colourway structure
                    // Check if current product is already in colourways by comparing IDs/labels
                    $currentProductId    = $currentProduct['id'] ?? $currentProduct['catentryId'] ?? null;
                    $currentProductLabel = $currentProduct['label'] ?? $currentProduct['colour'] ?? null;
                    $alreadyIncluded     = false;

                    if ( $currentProductId || $currentProductLabel ) {
                        foreach ( $colourways as $colourway ) {
                            $colourwayId    = $colourway['id'] ?? $colourway['catentryId'] ?? null;
                            $colourwayLabel = $colourway['label'] ?? $colourway['colour'] ?? null;

                            if ( ($currentProductId && $colourwayId === $currentProductId) || ($currentProductLabel && $colourwayLabel === $currentProductLabel) ) {
                                $alreadyIncluded = true;
                                break;
                            }
                        }
                    }

                    // If current product not already included, add it
                    if ( !$alreadyIncluded ) {
                        $colourways[] = $currentProduct;
                    }
                }
            }

            // Also check for relatedProducts that might be other colourways
            $relatedProducts = $this->findRelatedProductsInNextData($nextData);
            if ( !empty($relatedProducts) ) {
                foreach ( $relatedProducts as $relatedProduct ) {
                    // Check if it's already included
                    $relatedId       = $relatedProduct['id'] ?? $relatedProduct['catentryId'] ?? null;
                    $relatedLabel    = $relatedProduct['label'] ?? $relatedProduct['colour'] ?? null;
                    $alreadyIncluded = false;

                    if ( $relatedId || $relatedLabel ) {
                        foreach ( $colourways as $colourway ) {
                            $colourwayId    = $colourway['id'] ?? $colourway['catentryId'] ?? null;
                            $colourwayLabel = $colourway['label'] ?? $colourway['colour'] ?? null;

                            if ( ($relatedId && $colourwayId === $relatedId) || ($relatedLabel && $colourwayLabel === $relatedLabel) ) {
                                $alreadyIncluded = true;
                                break;
                            }
                        }
                    }

                    // If related product has variants, include it
                    if ( !$alreadyIncluded && isset($relatedProduct['variants']) && is_array($relatedProduct['variants']) && !empty($relatedProduct['variants']) ) {
                        $colourways[] = $relatedProduct;
                    }
                }
            }

            if ( empty($colourways) ) {
                Log::debug('No colourways found in __NEXT_DATA__', ['url' => $url]);

                return [];
            }

            // Build normalized variant matrix
            $variantMatrix = [];
            foreach ( $colourways as $colourway ) {
                $normalizedColour = $this->normalizeColourway($colourway);
                if ( $normalizedColour !== null ) {
                    $variantMatrix[] = $normalizedColour;
                }
            }

            unset($nextData, $colourways);

            return $variantMatrix;
        } catch ( Exception $e ) {
            Log::warning('Error extracting variant matrix from __NEXT_DATA__', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Recursively search for colourways array in Next.js data structure.
     *
     * @param array $data The decoded JSON data
     *
     * @return array Array of colourway objects
     */
    private function findColourwaysInNextData (array $data) : array {
        // Try known paths first
        $knownPaths = [
            ['props', 'pageProps', 'product', 'colourways'],
            ['props', 'pageProps', 'colourways'],
            ['props', 'pageProps', 'productData', 'colourways'],
            ['query', 'product', 'colourways'],
        ];

        foreach ( $knownPaths as $path ) {
            $value = $data;
            $found = true;
            foreach ( $path as $key ) {
                if ( !isset($value[$key]) || !is_array($value[$key]) ) {
                    $found = false;
                    break;
                }
                $value = $value[$key];
            }
            if ( $found && is_array($value) && !empty($value) ) {
                // Verify it looks like colourways (has id, colour, variants)
                if ( isset($value[0]) && is_array($value[0]) ) {
                    $first = $value[0];
                    if ( isset($first['id']) && (isset($first['colour']) || isset($first['label'])) && isset($first['variants']) ) {
                        return $value;
                    }
                }
            }
        }

        // Recursive search for array of objects with id, colour/variants structure
        return $this->recursiveSearchColourways($data);
    }

    /**
     * Recursively search for colourways structure.
     *
     * @param mixed $data  The data to search
     * @param int   $depth Current recursion depth
     *
     * @return array Array of colourways found
     */
    private function recursiveSearchColourways ($data, int $depth = 0) : array {
        // Limit recursion depth to prevent infinite loops
        if ( $depth > 10 ) {
            return [];
        }

        if ( !is_array($data) ) {
            return [];
        }

        // Check if this array looks like a colourways array
        if ( !empty($data) && isset($data[0]) && is_array($data[0]) ) {
            $first = $data[0];
            // Look for key indicators of a colourway object
            if ( isset($first['id']) && (isset($first['colour']) || isset($first['label']) || isset($first['colourCode'])) && isset($first['variants']) && is_array($first['variants']) ) {
                // Verify variants have size and stockAvailability
                if ( !empty($first['variants']) && isset($first['variants'][0]) && is_array($first['variants'][0]) ) {
                    $firstVariant = $first['variants'][0];
                    if ( isset($firstVariant['size']) && isset($firstVariant['stockAvailability']) ) {
                        return $data;
                    }
                }
            }
        }

        // Recursively search nested arrays
        foreach ( $data as $value ) {
            if ( is_array($value) ) {
                $result = $this->recursiveSearchColourways($value, $depth + 1);
                if ( !empty($result) ) {
                    return $result;
                }
            }
        }

        return [];
    }

    /**
     * Find the current product object in Next.js data structure.
     * The current product might be structured as a single object (not in a colourways array).
     *
     * @param array $data The decoded JSON data
     *
     * @return array|null Current product object or null if not found
     */
    private function findCurrentProductInNextData (array $data) : ?array {
        // Try known paths for current product
        $knownPaths = [
            ['props', 'pageProps', 'product'],
            ['props', 'pageProps', 'productData'],
            ['query', 'product'],
            ['props', 'pageProps', 'initialProduct'],
        ];

        foreach ( $knownPaths as $path ) {
            $value = $data;
            $found = true;
            foreach ( $path as $key ) {
                if ( !isset($value[$key]) || !is_array($value[$key]) ) {
                    $found = false;
                    break;
                }
                $value = $value[$key];
            }
            if ( $found && is_array($value) && !empty($value) ) {
                // Check if it looks like a product with variants
                if ( isset($value['variants']) && is_array($value['variants']) && !empty($value['variants']) ) {
                    $firstVariant = $value['variants'][0] ?? null;
                    if ( $firstVariant && is_array($firstVariant) ) {
                        // Check for variants with size and stockAvailability (colourway structure)
                        if ( isset($firstVariant['size']) && isset($firstVariant['stockAvailability']) ) {
                            return $value;
                        }
                        // Check for variants with colour and size (flat structure that needs grouping)
                        if ( isset($firstVariant['colour']) && isset($firstVariant['size']) && isset($firstVariant['stockAvailability']) ) {
                            return $value;
                        }
                    }
                }
                // Also check if it has colour/label and variants (colourway structure)
                if ( (isset($value['colour']) || isset($value['label'])) && isset($value['variants']) && is_array($value['variants']) ) {
                    $firstVariant = $value['variants'][0] ?? null;
                    if ( $firstVariant && is_array($firstVariant) && isset($firstVariant['size']) && isset($firstVariant['stockAvailability']) ) {
                        return $value;
                    }
                }
            }
        }

        // Recursive search for a single product object with variants
        return $this->recursiveSearchCurrentProduct($data);
    }

    /**
     * Recursively search for current product object with variants.
     *
     * @param mixed $data  The data to search
     * @param int   $depth Current recursion depth
     *
     * @return array|null Product object or null
     */
    private function recursiveSearchCurrentProduct ($data, int $depth = 0) : ?array {
        // Limit recursion depth
        if ( $depth > 10 ) {
            return null;
        }

        if ( !is_array($data) ) {
            return null;
        }

        // Check if this is a product object (not an array of products)
        // It should have variants array with size and stockAvailability
        if ( isset($data['variants']) && is_array($data['variants']) && !empty($data['variants']) ) {
            $firstVariant = $data['variants'][0] ?? null;
            if ( $firstVariant && is_array($firstVariant) ) {
                // Check for variants with size and stockAvailability (colourway structure)
                if ( isset($firstVariant['size']) && isset($firstVariant['stockAvailability']) ) {
                    // This looks like a product/colourway with variants
                    return $data;
                }
                // Check for variants with colour and size (flat structure that needs grouping)
                if ( isset($firstVariant['colour']) && isset($firstVariant['size']) && isset($firstVariant['stockAvailability']) ) {
                    return $data;
                }
            }
        }

        // Recursively search nested arrays (but skip if it's a numeric array - those are likely colourways arrays)
        foreach ( $data as $key => $value ) {
            if ( is_array($value) ) {
                // Skip numeric arrays (they're likely colourways arrays, not single products)
                if ( !is_numeric($key) ) {
                    $result = $this->recursiveSearchCurrentProduct($value, $depth + 1);
                    if ( $result !== null ) {
                        return $result;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Group variants by colour when variants have a colour field.
     *
     * @param array $product Product object with variants array
     *
     * @return array Array of colourway objects
     */
    private function groupVariantsByColour (array $product) : array {
        $variants = $product['variants'] ?? [];
        if ( empty($variants) || !is_array($variants) ) {
            return [];
        }

        $grouped = [];

        foreach ( $variants as $variant ) {
            if ( !is_array($variant) || !isset($variant['colour']) ) {
                continue;
            }

            $colour = $variant['colour'];

            // Initialize colourway if not exists
            if ( !isset($grouped[$colour]) ) {
                $grouped[$colour] = [
                    'id'         => $product['id'] ?? null,
                    'colourCode' => $product['colourCode'] ?? null,
                    'label'      => $colour,
                    'colour'     => $colour,
                    'mainColour' => $product['mainColour'] ?? null,
                    'url'        => $product['url'] ?? null,
                    'soldOut'    => $product['soldOut'] ?? false,
                    'price'      => $product['price'] ?? null,
                    'variants'   => [],
                ];
            }

            // Add variant to this colourway
            $grouped[$colour]['variants'][] = $variant;
        }

        return array_values($grouped);
    }

    /**
     * Find related products in Next.js data structure.
     *
     * @param array $data The decoded JSON data
     *
     * @return array Array of related product objects
     */
    private function findRelatedProductsInNextData (array $data) : array {
        // Try known paths for relatedProducts
        $knownPaths = [
            ['props', 'pageProps', 'product', 'relatedProducts'],
            ['props', 'pageProps', 'relatedProducts'],
            ['props', 'pageProps', 'productData', 'relatedProducts'],
            ['query', 'product', 'relatedProducts'],
        ];

        foreach ( $knownPaths as $path ) {
            $value = $data;
            $found = true;
            foreach ( $path as $key ) {
                if ( !isset($value[$key]) ) {
                    $found = false;
                    break;
                }
                $value = $value[$key];
            }
            if ( $found && is_array($value) && !empty($value) ) {
                return $value;
            }
        }

        // Recursive search
        return $this->recursiveSearchRelatedProducts($data);
    }

    /**
     * Recursively search for relatedProducts array.
     *
     * @param mixed $data  The data to search
     * @param int   $depth Current recursion depth
     *
     * @return array Array of related products
     */
    private function recursiveSearchRelatedProducts ($data, int $depth = 0) : array {
        if ( $depth > 10 || !is_array($data) ) {
            return [];
        }

        // Check if this is a relatedProducts array
        if ( isset($data['relatedProducts']) && is_array($data['relatedProducts']) && !empty($data['relatedProducts']) ) {
            return $data['relatedProducts'];
        }

        // Recursively search
        foreach ( $data as $value ) {
            if ( is_array($value) ) {
                $result = $this->recursiveSearchRelatedProducts($value, $depth + 1);
                if ( !empty($result) ) {
                    return $result;
                }
            }
        }

        return [];
    }

    /**
     * Normalize a colourway object into the standard variant matrix format.
     *
     * @param array $colourway The colourway object from JSON
     *
     * @return array|null Normalized colourway array or null if invalid
     */
    private function normalizeColourway (array $colourway) : ?array {
        $colourLabel = $colourway['label'] ?? $colourway['colour'] ?? null;
        if ( !$colourLabel ) {
            return null;
        }

        $variants = $colourway['variants'] ?? [];
        if ( !is_array($variants) || empty($variants) ) {
            return null;
        }

        // Normalize colour slug
        $colourSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $colourLabel));
        $colourSlug = trim($colourSlug, '-');

        // Build normalized variants
        $normalizedVariants = [];
        foreach ( $variants as $variant ) {
            if ( !is_array($variant) ) {
                continue;
            }

            $size = $variant['size'] ?? null;
            if ( !$size ) {
                continue;
            }

            // Get price from variant or fallback to colourway
            $price = null;
            if ( isset($variant['price']['price']) ) {
                $price = (float) $variant['price']['price'];
            } elseif ( isset($colourway['price']['price']) ) {
                $price = (float) $colourway['price']['price'];
            }

            // Get stock availability
            $stockAvailability = isset($variant['stockAvailability']) ? (bool) $variant['stockAvailability'] : false;

            // Get SKU and catentry ID
            $sku        = $variant['id'] ?? $variant['catentryId'] ?? null;
            $catentryId = $variant['catentryId'] ?? null;

            $normalizedVariants[] = [
                'size'               => $size,
                'sku'                => $sku,
                'catentry_id'        => $catentryId,
                'price'              => $price,
                'stock_availability' => $stockAvailability,
            ];
        }

        if ( empty($normalizedVariants) ) {
            return null;
        }

        // Build normalized colourway
        $normalized = [
            'colour_label' => $colourLabel,
            'colour_code'  => $colourway['colourCode'] ?? null,
            'main_colour'  => $colourway['mainColour'] ?? null,
            'colour_slug'  => $colourSlug,
            'swatch_url'   => $colourway['swatchUrl'] ?? $colourway['swatch_url'] ?? null,
            'sold_out'     => isset($colourway['soldOut']) ? (bool) $colourway['soldOut'] : false,
            'pdp_url'      => $this->buildAbsoluteUrl($colourway['url'] ?? ''),
            'base_price'   => isset($colourway['price']['price']) ? (float) $colourway['price']['price'] : null,
            'variants'     => $normalizedVariants,
        ];

        return $normalized;
    }

    /**
     * Build absolute URL from relative path.
     *
     * @param string $relativeUrl Relative URL from JSON
     *
     * @return string Absolute URL
     */
    private function buildAbsoluteUrl (string $relativeUrl) : string {
        if ( empty($relativeUrl) ) {
            return '';
        }

        if ( preg_match('/^https?:\/\//', $relativeUrl) ) {
            return $relativeUrl;
        }

        $baseUrl     = rtrim(self::BASE_URL, '/');
        $relativeUrl = ltrim($relativeUrl, '/');

        return $baseUrl . '/' . $relativeUrl;
    }

    /**
     * Fetch variant PDP pages and attach their images to the variant matrix.
     *
     * @param array  $variantMatrix The variant matrix with colour variants
     * @param string $currentUrl    The current PDP URL (to skip fetching it again)
     * @param string $originalUrl   The original URL for logging
     * @param array  $baseImages    Base images from the main PDP (to use for current variant)
     *
     * @return array Updated variant matrix with images attached
     */
    private function fetchAndAttachVariantImages (array $variantMatrix, string $currentUrl, string $originalUrl, array $baseImages = []) : array {
        // Collect unique variant PDP URLs (excluding the current one)
        $variantUrls       = [];
        $urlToVariantIndex = [];

        foreach ( $variantMatrix as $index => $variant ) {
            $pdpUrl = $variant['pdp_url'] ?? '';

            if ( empty($pdpUrl) ) {
                // Initialize images array as empty if no URL
                $variantMatrix[$index]['images'] = [];
                continue;
            }

            // Normalize URLs for comparison (remove trailing slashes, query params, etc.)
            $currentPath = parse_url($currentUrl, PHP_URL_PATH);
            $variantPath = parse_url($pdpUrl, PHP_URL_PATH);

            $normalizedCurrent = $currentPath ? rtrim($currentPath, '/') : '';
            $normalizedVariant = $variantPath ? rtrim($variantPath, '/') : '';

            // Skip if this is the current PDP URL - use base images instead
            if ( $normalizedCurrent && $normalizedVariant && $normalizedCurrent === $normalizedVariant ) {
                // Use base images from main PDP for this variant
                $variantMatrix[$index]['images'] = $baseImages;
                continue;
            }

            // Add to fetch list if not already added
            if ( !isset($variantUrls[$pdpUrl]) ) {
                $variantUrls[$pdpUrl]       = $pdpUrl;
                $urlToVariantIndex[$pdpUrl] = [];
            }
            $urlToVariantIndex[$pdpUrl][] = $index;
        }

        if ( empty($variantUrls) ) {
            Log::debug('No variant URLs to fetch', ['original_url' => $originalUrl]);

            return $variantMatrix;
        }

        Log::info('Fetching variant PDP pages for images', [
            'original_url'  => $originalUrl,
            'variant_count' => count($variantUrls),
        ]);

        // Fetch variant pages in batches
        $variantUrlsArray = array_values($variantUrls);
        $variantBatches   = array_chunk($variantUrlsArray, self::VARIANT_PAGE_CONCURRENCY);

        foreach ( $variantBatches as $batchIndex => $variantBatch ) {
            $batchResults = $this->fetchVariantPages($variantBatch);

            // Process results and attach images to variants
            foreach ( $batchResults as $variantUrl => $result ) {
                $variantIndices = $urlToVariantIndex[$variantUrl] ?? [];

                if ( empty($variantIndices) ) {
                    continue;
                }

                $variantImages = [];

                if ( $result['success'] && !empty($result['html']) ) {
                    try {
                        $variantCrawler = new Crawler($result['html']);
                        $variantImages  = $this->extractImages($variantCrawler);
                        unset($variantCrawler);
                    } catch ( Exception $e ) {
                        Log::warning('Error extracting images from variant PDP', [
                            'variant_url' => $variantUrl,
                            'colour_slug' => $variantMatrix[$variantIndices[0]]['colour_slug'] ?? 'unknown',
                            'error'       => $e->getMessage(),
                        ]);
                    }
                } else {
                    Log::warning('Failed to fetch variant PDP page', [
                        'variant_url' => $variantUrl,
                        'colour_slug' => $variantMatrix[$variantIndices[0]]['colour_slug'] ?? 'unknown',
                        'error'       => $result['error'] ?? 'Unknown error',
                        'status_code' => $result['statusCode'] ?? null,
                    ]);
                }

                // Attach images to all variants that share this URL
                foreach ( $variantIndices as $variantIndex ) {
                    $variantMatrix[$variantIndex]['images'] = $variantImages;
                }

                unset($result['html']);
            }

            unset($batchResults);

            // Trigger GC periodically
            if ( $batchIndex > 0 && $batchIndex % self::GC_COLLECT_INTERVAL === 0 ) {
                gc_collect_cycles();
            }
        }

        unset($variantUrls, $urlToVariantIndex, $variantUrlsArray, $variantBatches);

        return $variantMatrix;
    }

    /**
     * Fetch variant PDP pages using multi-cURL.
     *
     * @param array $variantUrls Array of variant PDP URLs to fetch
     * @param int   $concurrency Number of concurrent requests (defaults to VARIANT_PAGE_CONCURRENCY)
     *
     * @return array Associative array [url => ['success' => bool, 'html' => string|null, 'finalUrl' => string|null,
     *               'error' => string|null, 'statusCode' => int|null]]
     */
    private function fetchVariantPages (array $variantUrls, int $concurrency = self::VARIANT_PAGE_CONCURRENCY) : array {
        $results     = [];
        $multiHandle = curl_multi_init();
        $handles     = [];

        foreach ( $variantUrls as $url ) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => self::CURL_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::CURL_CONNECT_TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_ENCODING       => 'gzip, deflate, br',
                CURLOPT_TCP_KEEPALIVE  => 1,
                CURLOPT_TCP_KEEPIDLE   => 300,
                CURLOPT_TCP_KEEPINTVL  => 300,
                CURLOPT_HTTPHEADER     => [
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
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
            $handles[$url] = $ch;
            $results[$url] = [
                'success'    => false,
                'html'       => null,
                'finalUrl'   => null,
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

        foreach ( $handles as $url => $ch ) {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $error    = curl_error($ch);

            if ( $error ) {
                $results[$url]['error'] = $error;
            } elseif ( $httpCode >= 200 && $httpCode < 300 ) {
                $html          = curl_multi_getcontent($ch);
                $results[$url] = [
                    'success'    => true,
                    'html'       => $html,
                    'finalUrl'   => $finalUrl,
                    'error'      => null,
                    'statusCode' => $httpCode,
                ];
            } else {
                $results[$url]['statusCode'] = $httpCode;
                $results[$url]['error']      = "HTTP {$httpCode}";
            }

            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }

        curl_multi_close($multiHandle);
        unset($handles, $multiHandle);

        return $results;
    }

    /**
     * Merge variant images into the main images array with deduplication.
     *
     * @param array $baseImages    Base images from the main PDP
     * @param array $variantMatrix Variant matrix with images attached
     *
     * @return array Merged and deduplicated images array
     */
    private function mergeVariantImages (array $baseImages, array $variantMatrix) : array {
        $allImages = $baseImages;
        $seenUrls  = [];

        // Track seen URLs from base images
        foreach ( $baseImages as $image ) {
            $imageUrl = $image['url'] ?? '';
            if ( $imageUrl ) {
                $seenUrls[$imageUrl] = true;
            }
        }

        // Add variant images (deduplicated)
        foreach ( $variantMatrix as $variant ) {
            $variantImages = $variant['images'] ?? [];

            if ( empty($variantImages) || !is_array($variantImages) ) {
                continue;
            }

            foreach ( $variantImages as $image ) {
                $imageUrl = $image['url'] ?? '';

                if ( empty($imageUrl) ) {
                    continue;
                }

                // Skip if already seen
                if ( isset($seenUrls[$imageUrl]) ) {
                    continue;
                }

                $allImages[]         = $image;
                $seenUrls[$imageUrl] = true;
            }
        }

        return $allImages;
    }

    /**
     * Retry failed URLs with exponential backoff.
     */
    private function retryFailedUrls (array $retryUrls, int &$processed, int &$successful, int &$failed) : void {
        foreach ( $retryUrls as $retryData ) {
            $url      = $retryData['url'];
            $finalUrl = $retryData['finalUrl'] ?? $url;
            $httpCode = $retryData['httpCode'];
            $error    = $retryData['error'];
            $attempt  = $retryData['attempt'];

            for ( $retryAttempt = $attempt; $retryAttempt <= self::MAX_RETRIES; $retryAttempt++ ) {
                $waitTime = self::RETRY_DELAY_BASE * pow(2, $retryAttempt - 1);

                if ( $retryAttempt > 1 ) {
                    Log::info('Retrying failed product page', [
                        'url'                  => $url,
                        'attempt'              => $retryAttempt,
                        'max_retries'          => self::MAX_RETRIES,
                        'wait_seconds'         => $waitTime,
                        'previous_error'       => $error,
                        'previous_status_code' => $httpCode,
                    ]);
                    sleep($waitTime);
                }

                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL            => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS      => 5,
                    CURLOPT_TIMEOUT        => self::CURL_TIMEOUT,
                    CURLOPT_CONNECTTIMEOUT => self::CURL_CONNECT_TIMEOUT,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_ENCODING       => 'gzip, deflate, br',
                    CURLOPT_TCP_KEEPALIVE  => 1,
                    CURLOPT_TCP_KEEPIDLE   => 300,
                    CURLOPT_TCP_KEEPINTVL  => 300,
                    CURLOPT_HTTPHEADER     => [
                        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                        'Accept-Language: en-US,en;q=0.9,nl;q=0.8',
                        'Accept-Encoding: gzip, deflate, br, zstd',
                        'Referer: ' . self::BASE_URL . '/',
                        'Origin: ' . self::BASE_URL,
                        'Connection: keep-alive',
                        'Upgrade-Insecure-Requests: 1',
                        'Sec-Fetch-Dest: document',
                        'Sec-Fetch-Mode: navigate',
                        'Sec-Fetch-Site: none',
                        'Sec-Fetch-User: ?1',
                        'Cache-Control: max-age=0',
                    ],
                ]);

                $html          = curl_exec($ch);
                $retryHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $retryFinalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
                $retryError    = curl_error($ch);
                curl_close($ch);

                if ( $retryError ) {
                    if ( $retryAttempt >= self::MAX_RETRIES ) {
                        $failed++;
                        Log::warning('Failed to fetch product page after all retries', [
                            'url'            => $url,
                            'error'          => $retryError,
                            'total_attempts' => $retryAttempt,
                        ]);
                    }
                    continue;
                }

                if ( $retryHttpCode >= 200 && $retryHttpCode < 300 ) {
                    try {
                        $this->parseAndSaveProduct($url, $html, $retryFinalUrl);
                        $successful++;
                        Log::info('Successfully fetched product page after retry', [
                            'url'     => $url,
                            'attempt' => $retryAttempt,
                        ]);
                        unset($html);
                        break;
                    } catch ( Exception $e ) {
                        $failed++;
                        Log::error('Error parsing/saving product after retry', [
                            'url'     => $url,
                            'error'   => $e->getMessage(),
                            'attempt' => $retryAttempt,
                        ]);
                        unset($html);
                        break;
                    }
                } else {
                    $isRetryable = in_array($retryHttpCode, [429, 502, 503, 504, 520, 521, 522, 523, 524]);
                    if ( $retryAttempt >= self::MAX_RETRIES || !$isRetryable ) {
                        $failed++;
                        Log::warning('Failed to fetch product page after retries', [
                            'url'            => $url,
                            'error'          => "HTTP {$retryHttpCode}",
                            'status_code'    => $retryHttpCode,
                            'total_attempts' => $retryAttempt,
                        ]);
                        break;
                    }
                }
            }
        }
    }

    /**
     * Format Scene7 URL to force JPG format with max width.
     *
     * @param string $url Original Scene7 URL
     *
     * @return string Formatted URL with fmt=jpg&wid=1920
     */
    private function formatScene7Url (string $url) : string {
        // Check if it's a Scene7 URL
        if ( !str_contains($url, 'scene7.com') && !str_contains($url, 'scene7') ) {
            return $url;
        }

        // Parse URL
        $parsed = parse_url($url);
        if ( !$parsed ) {
            return $url;
        }

        // For Scene7 URLs, we want to replace the query string entirely
        // Scene7 template variables (like $b2c_updp_m_mainImage_1920$) should be removed
        // and replaced with our clean parameters
        
        // Rebuild URL with clean query parameters
        $scheme = $parsed['scheme'] ?? 'https';
        $host   = $parsed['host'] ?? '';
        $path   = $parsed['path'] ?? '';
        
        // Use only fmt and wid parameters for clean Scene7 URLs
        $query = 'fmt=jpg&wid=1920';

        return "{$scheme}://{$host}{$path}?{$query}";
    }

    /**
     * Download and save image from URL.
     *
     * @param string  $imageUrl   Original image URL
     * @param Product $product    Product model
     * @param string  $slug       Product slug
     * @param int     $imageIndex Image index for variant identification
     *
     * @return string|null Relative path to saved image (e.g., 'images/TommyHilfiger/th_12_tanga_white_1702562345.jpg') or null on failure
     */
    private function downloadAndSaveImage (string $imageUrl, Product $product, string $slug, int $imageIndex) : ?string {
        try {
            // Optional caching: Check if image already exists for this product/variant
            $cachedPath = $this->checkCachedImage($product->id, $slug, $imageIndex, $imageUrl);
            if ( $cachedPath ) {
                return $cachedPath;
            }

            // Format Scene7 URL if needed
            $formattedUrl = $this->formatScene7Url($imageUrl);

            // Download image with increased timeout for large images
            $response = Http::timeout(60)
                ->connectTimeout(30)
                ->withHeaders([
                    'Accept'     => 'image/*',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                ])
                ->get($formattedUrl);

            if ( !$response->successful() ) {
                Log::warning('Failed to download image', [
                    'product_id' => $product->id,
                    'url'        => $formattedUrl,
                    'status'     => $response->status(),
                ]);

                return null;
            }

            // Always use JPG extension (we force fmt=jpg in Scene7 URLs)
            $extension = 'jpg';

            // Generate filename: th_{product_id}_{slug}_{variant}_{timestamp}.jpg
            $variant     = $imageIndex > 0 ? $imageIndex : '';
            $timestamp   = time();
            $filename    = $this->generateImageFilename($product->id, $slug, $variant, $timestamp, $extension);

            // Save to storage/app/public/images/TommyHilfiger/
            $path = "images/TommyHilfiger/{$filename}";
            Storage::disk('public')->put($path, $response->body());

            // Return relative path for source_url
            return $path;
        } catch ( Exception $e ) {
            Log::error('Error downloading image', [
                'product_id' => $product->id,
                'url'       => $imageUrl,
                'error'     => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Check if image is already cached (optional caching).
     * Looks for existing files with the same product_id, slug, and variant pattern.
     *
     * @param int    $productId Product ID
     * @param string $slug      Product slug
     * @param int    $imageIndex Image index
     * @param string $imageUrl  Original image URL (for hash comparison)
     *
     * @return string|null Cached file path or null if not found
     */
    private function checkCachedImage (int $productId, string $slug, int $imageIndex, string $imageUrl) : ?string {
        try {
            // Generate pattern to search for existing files
            $cleanSlug   = Str::slug($slug);
            $variant     = $imageIndex > 0 ? $imageIndex : '';
            $variantPart = $variant !== '' ? "_{$variant}" : '';
            $pattern     = "th_{$productId}_{$cleanSlug}{$variantPart}_*.jpg";

            // Search for existing files in images/TommyHilfiger directory
            $files = Storage::disk('public')->files('images/TommyHilfiger');
            foreach ( $files as $file ) {
                // Check if file matches pattern
                $basename = basename($file);
                // Convert productId to string for preg_quote
                $productIdStr = (string) $productId;
                if ( preg_match('/^th_' . preg_quote($productIdStr, '/') . '_' . preg_quote($cleanSlug, '/') . preg_quote($variantPart, '/') . '_\d+\.jpg$/', $basename) ) {
                    // Check if file is recent (within last hour) - optional time-based cache
                    $lastModified = Storage::disk('public')->lastModified($file);
                    if ( $lastModified && (time() - $lastModified) < 3600 ) {
                        // Return relative path
                        return $file;
                    }
                }
            }
        } catch ( Exception $e ) {
            // If caching check fails, proceed with download
            Log::debug('Cache check failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Extract file extension from Content-Type header.
     *
     * @param string|null $contentType Content-Type header value
     *
     * @return string File extension (defaults to 'jpg')
     */
    private function extractExtensionFromContentType (?string $contentType) : string {
        if ( !$contentType ) {
            return 'jpg';
        }

        $mimeMap = [
            'image/jpeg'      => 'jpg',
            'image/jpg'       => 'jpg',
            'image/png'       => 'png',
            'image/gif'       => 'gif',
            'image/webp'      => 'webp',
        ];

        // Extract MIME type (remove charset, etc.)
        $mimeType = explode(';', $contentType)[0];
        $mimeType = trim($mimeType);

        return $mimeMap[$mimeType] ?? 'jpg';
    }

    /**
     * Generate image filename following pattern: th_{product_id}_{slug}_{variant}_{timestamp}.jpg
     *
     * @param int    $productId Product ID
     * @param string $slug      Product slug
     * @param mixed  $variant   Variant identifier (index or hash)
     * @param int    $timestamp Timestamp
     * @param string $extension File extension (default: 'jpg')
     *
     * @return string Generated filename
     */
    private function generateImageFilename (int $productId, string $slug, $variant, int $timestamp, string $extension = 'jpg') : string {
        // Sanitize slug
        $cleanSlug = Str::slug($slug);
        if ( empty($cleanSlug) ) {
            $cleanSlug = 'product';
        }

        // Build variant part
        $variantPart = '';
        if ( $variant !== '' && $variant !== null ) {
            $variantPart = '_' . (is_string($variant) ? Str::slug($variant) : (string) $variant);
        }

        return "th_{$productId}_{$cleanSlug}{$variantPart}_{$timestamp}.{$extension}";
    }

    /**
     * Enrich variant matrix with local image paths for each colourway.
     *
     * @param array   $variantMatrix The variant matrix with colour variants
     * @param Product $product       Product model
     * @param string  $slug          Product slug
     *
     * @return array Enriched variant matrix with images_local key for each colourway
     */
    private function enrichVariantMatrixWithLocalImages (array $variantMatrix, Product $product, string $slug) : array {
        foreach ( $variantMatrix as $i => $colourway ) {
            $colourwayImages = $colourway['images'] ?? [];
            $downloadedPaths = [];

            if ( !empty($colourwayImages) && is_array($colourwayImages) ) {
                $colourSlug = $colourway['colour_slug'] ?? strtolower(preg_replace('/[^a-z0-9]+/i', '-', $colourway['colour_label'] ?? 'default'));
                $colourSlug = trim($colourSlug, '-');
                if ( empty($colourSlug) ) {
                    $colourSlug = 'default';
                }

                foreach ( $colourwayImages as $imageIndex => $image ) {
                    // Extract URL - handle both array and string formats
                    $imageUrl = null;
                    if ( is_array($image) && isset($image['url']) ) {
                        $imageUrl = $image['url'];
                    } elseif ( is_string($image) ) {
                        $imageUrl = $image;
                    }

                    if ( empty($imageUrl) ) {
                        continue;
                    }

                    // Download and save image
                    $localPath = $this->downloadAndSaveVariantImage($imageUrl, $product, $slug, $colourSlug, $imageIndex);

                    if ( $localPath ) {
                        $downloadedPaths[] = $localPath;
                    }
                }
            }

            // Always set images_local key (even if empty array)
            $variantMatrix[$i]['images_local'] = $downloadedPaths;
        }

        return $variantMatrix;
    }

    /**
     * Download and save variant image from URL with colour slug in filename to avoid collisions.
     *
     * @param string  $imageUrl   Original image URL
     * @param Product $product    Product model
     * @param string  $slug       Product slug
     * @param string  $colourSlug Colour slug for filename uniqueness
     * @param int     $imageIndex Image index for variant identification
     *
     * @return string|null Relative path to saved image (e.g., 'images/TommyHilfiger/th_12_tanga_white_0_1702562345.jpg') or null on failure
     */
    private function downloadAndSaveVariantImage (string $imageUrl, Product $product, string $slug, string $colourSlug, int $imageIndex) : ?string {
        try {
            // Format Scene7 URL if needed
            $formattedUrl = $this->formatScene7Url($imageUrl);

            // Download image with increased timeout for large images
            $response = Http::timeout(60)
                ->connectTimeout(30)
                ->withHeaders([
                    'Accept'     => 'image/*',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                ])
                ->get($formattedUrl);

            if ( !$response->successful() ) {
                Log::warning('Failed to download variant image', [
                    'product_id'  => $product->id,
                    'url'        => $formattedUrl,
                    'status'     => $response->status(),
                    'colour_slug' => $colourSlug,
                ]);

                return null;
            }

            // Always use JPG extension (we force fmt=jpg in Scene7 URLs)
            $extension = 'jpg';

            // Generate filename: th_{product_id}_{slug}_{colourSlug}_{imageIndex}_{timestamp}.jpg
            $timestamp = time();
            $filename  = $this->generateVariantImageFilename($product->id, $slug, $colourSlug, $imageIndex, $timestamp, $extension);

            // Save to storage/app/public/images/TommyHilfiger/
            $path = "images/TommyHilfiger/{$filename}";
            Storage::disk('public')->put($path, $response->body());

            // Return relative path for images_local
            return $path;
        } catch ( Exception $e ) {
            Log::error('Error downloading variant image', [
                'product_id'  => $product->id,
                'url'        => $imageUrl,
                'error'      => $e->getMessage(),
                'colour_slug' => $colourSlug,
            ]);

            return null;
        }
    }

    /**
     * Generate variant image filename following pattern: th_{product_id}_{slug}_{colourSlug}_{imageIndex}_{timestamp}.jpg
     *
     * @param int    $productId  Product ID
     * @param string $slug       Product slug
     * @param string $colourSlug Colour slug for uniqueness
     * @param int    $imageIndex Image index
     * @param int    $timestamp  Timestamp
     * @param string $extension  File extension (default: 'jpg')
     *
     * @return string Generated filename
     */
    private function generateVariantImageFilename (int $productId, string $slug, string $colourSlug, int $imageIndex, int $timestamp, string $extension = 'jpg') : string {
        // Sanitize slug
        $cleanSlug = Str::slug($slug);
        if ( empty($cleanSlug) ) {
            $cleanSlug = 'product';
        }

        // Sanitize colour slug
        $cleanColourSlug = Str::slug($colourSlug);
        if ( empty($cleanColourSlug) ) {
            $cleanColourSlug = 'default';
        }

        return "th_{$productId}_{$cleanSlug}_{$cleanColourSlug}_{$imageIndex}_{$timestamp}.{$extension}";
    }
}
