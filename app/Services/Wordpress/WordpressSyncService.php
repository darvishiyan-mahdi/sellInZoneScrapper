<?php

namespace App\Services\Wordpress;

use App\Models\Product;
use App\Models\ProductWordpressMapping;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * WordPress/WooCommerce Sync Service
 *
 * Handles synchronization of products from local database to WooCommerce,
 * including variable products with variations.
 */
class WordpressSyncService {
    protected WooCommerceApiClient        $apiClient;
    protected WooCommerceAttributeService $attributeService;
    protected ProductWeightDetector       $weightDetector;

    public function __construct (
        WooCommerceApiClient $apiClient, 
        WooCommerceAttributeService $attributeService,
        ProductWeightDetector $weightDetector
    ) {
        $this->apiClient        = $apiClient;
        $this->attributeService = $attributeService;
        $this->weightDetector   = $weightDetector;
    }

    /**
     * Sync a product to WooCommerce (create or update)
     */
    public function syncProduct (Product $product) : void {
        Log::info("=== Starting sync for product", [
            'product_id' => $product->id,
            'product_title' => $product->title,
            'product_status' => $product->status,
        ]);

        try {
            $mapping      = $product->wordpressMapping;
            $isNewProduct = !$mapping || !$mapping->wordpress_product_id;

            Log::info("Product sync status check", [
                'product_id' => $product->id,
                'is_new_product' => $isNewProduct,
                'existing_woo_id' => $mapping->wordpress_product_id ?? null,
            ]);

            // Check if product has variations
            $variantMatrix = $this->getVariantMatrix($product);
            $hasVariations = !empty($variantMatrix);

            Log::info("Product variation check", [
                'product_id' => $product->id,
                'has_variations' => $hasVariations,
                'variant_count' => $hasVariations ? count($variantMatrix) : 0,
            ]);

            if ( $isNewProduct ) {
                Log::info("Creating new product in WooCommerce", ['product_id' => $product->id]);
                $this->createProduct($product, $variantMatrix, $hasVariations);
            } else {
                Log::info("Updating existing product in WooCommerce", [
                    'product_id' => $product->id,
                    'woo_product_id' => $mapping->wordpress_product_id,
                ]);
                $this->updateProduct($product, $mapping, $variantMatrix, $hasVariations);
            }

            Log::info("=== Successfully completed sync for product", [
                'product_id' => $product->id,
                'product_title' => $product->title,
            ]);
        } catch ( Exception $e ) {
            Log::error("=== Failed to sync product", [
                'product_id' => $product->id,
                'product_title' => $product->title,
                'error' => $e->getMessage(),
            ]);
            $this->handleSyncError($product, $e);
            throw $e;
        }
    }

    /**
     * Get variant matrix from product attributes
     */
    protected function getVariantMatrix (Product $product) : ?array {
        $variantAttribute = $product->attributes()->where('name', 'variant_matrix')->first();

        if ( !$variantAttribute || empty($variantAttribute->value) ) {
            return null;
        }

        $variantMatrix = json_decode($variantAttribute->value, true);

        if ( !is_array($variantMatrix) || empty($variantMatrix) ) {
            return null;
        }

        return $variantMatrix;
    }

    /**
     * Create a new product in WooCommerce
     */
    protected function createProduct (Product $product, ?array $variantMatrix, bool $hasVariations) : void {
        Log::info("Step 1: Building product payload", [
            'product_id' => $product->id,
            'type' => $hasVariations ? 'variable' : 'simple',
        ]);

        // Build product payload
        $productData = $this->buildProductPayload($product, $variantMatrix, $hasVariations);

        Log::info("Step 2: Product payload built", [
            'product_id' => $product->id,
            'payload_keys' => array_keys($productData),
            'has_images' => !empty($productData['images']),
            'image_count' => count($productData['images'] ?? []),
            'has_categories' => !empty($productData['categories']),
            'has_attributes' => !empty($productData['attributes']),
        ]);

        Log::info("Step 3: Creating product via WooCommerce API", ['product_id' => $product->id]);

        // Create the product
        $response     = $this->apiClient->upsertProduct(null, $productData);
        $wooProductId = $response['id'];

        Log::info("Step 4: Product created in WooCommerce", [
            'product_id' => $product->id,
            'woo_product_id' => $wooProductId,
        ]);

        // Create variations if this is a variable product
        if ( $hasVariations && !empty($variantMatrix) ) {
            Log::info("Step 5: Creating variations", [
                'product_id' => $product->id,
                'woo_product_id' => $wooProductId,
            ]);
            $this->createVariations($wooProductId, $product, $variantMatrix);
        } else {
            Log::info("Step 5: Skipping variations (simple product)", ['product_id' => $product->id]);
        }

        Log::info("Step 6: Saving product mapping to database", [
            'product_id' => $product->id,
            'woo_product_id' => $wooProductId,
        ]);

        // Save mapping
        $this->saveMapping($product, $wooProductId, 'success', $response);

        Log::info("Product creation completed successfully", [
            'product_id'     => $product->id,
            'woo_product_id' => $wooProductId,
            'product_type' => $hasVariations ? 'variable' : 'simple',
        ]);
    }

    /**
     * Build product payload for WooCommerce API
     */
    protected function buildProductPayload (Product $product, ?array $variantMatrix, bool $hasVariations) : array {
        Log::info("Building product payload", [
            'product_id' => $product->id,
            'has_variations' => $hasVariations,
        ]);

        // Use description_translated from meta if available, otherwise fall back to regular description
        $description = '';
        if ($product->meta && is_array($product->meta) && !empty($product->meta['description_translated'])) {
            $description = $product->meta['description_translated'];
            Log::info("Using description_translated from meta", [
                'product_id' => $product->id,
                'description_length' => strlen($description),
            ]);
        } else {
            $description = $product->description ?? '';
            Log::info("Using regular description", [
                'product_id' => $product->id,
                'description_length' => strlen($description),
            ]);
        }

        $payload = [
            'name'        => $product->title,
            'description' => $description,
            'type'        => $hasVariations ? 'variable' : 'simple',
            'status'      => $this->mapStatus($product->status),
        ];

        Log::info("Basic product fields set", [
            'product_id' => $product->id,
            'name' => $payload['name'],
            'type' => $payload['type'],
            'status' => $payload['status'],
        ]);

        // Add SKU if available
        if ( $product->external_id ) {
            $payload['sku'] = (string) $product->external_id;
            Log::info("SKU added to payload", [
                'product_id' => $product->id,
                'sku' => $payload['sku'],
            ]);
        }

        // For simple products, set price and stock directly
        if ( !$hasVariations ) {
            if ( $product->price ) {
                $calculatedPrice = $this->calculateFinalPrice($product->price, $product);
                $payload['regular_price'] = (string) $calculatedPrice;
            }
            
            // Detect and set weight
            $weight = $this->weightDetector->detectWeightFromName($product->title);
            $payload['weight'] = (string) $weight;
            
            if ( $product->stock_quantity !== null ) {
                $payload['manage_stock']   = true;
                $payload['stock_quantity'] = $product->stock_quantity;
                $payload['stock_status']   = $product->stock_quantity > 0 ? 'instock' : 'outofstock';
            } else {
                $payload['stock_status'] = 'instock';
            }
            Log::info("Simple product stock/price set", [
                'product_id' => $product->id,
                'price' => $payload['regular_price'] ?? null,
                'weight' => $payload['weight'] ?? null,
                'stock_quantity' => $payload['stock_quantity'] ?? null,
                'stock_status' => $payload['stock_status'] ?? null,
            ]);
        } else {
            // For variable products, stock is managed at variation level
            $payload['manage_stock'] = false;
            Log::info("Variable product - stock managed at variation level", [
                'product_id' => $product->id,
            ]);
        }

        // Add images
        $images = $this->buildImagesArray($product);
        if ( !empty($images) ) {
            $payload['images'] = $images;
            Log::info("Images added to payload", [
                'product_id' => $product->id,
                'image_count' => count($images),
            ]);
        } else {
            Log::info("No images to add", ['product_id' => $product->id]);
        }

        // Add categories
        $categories = $this->getCategoryIds($product);
        if ( !empty($categories) ) {
            $payload['categories'] = $categories;
            Log::info("Categories added to payload", [
                'product_id' => $product->id,
                'category_count' => count($categories),
                'category_ids' => array_column($categories, 'id'),
            ]);
        } else {
            Log::info("No categories to add", ['product_id' => $product->id]);
        }

        // Add attributes for variable products
        if ( $hasVariations && !empty($variantMatrix) ) {
            Log::info("Preparing attributes for variable product", [
                'product_id' => $product->id,
            ]);
            $attributes = $this->attributeService->prepareProductAttributes($variantMatrix);
            if ( !empty($attributes) ) {
                $payload['attributes'] = $attributes;
                Log::info("Attributes added to payload", [
                    'product_id' => $product->id,
                    'attribute_count' => count($attributes),
                ]);
            }
        }

        // Add custom meta fields
        $metaData = $this->buildMetaData($product);
        if ( !empty($metaData) ) {
            $payload['meta_data'] = $metaData;
            Log::info("Meta data added to payload", [
                'product_id' => $product->id,
                'meta_count' => count($metaData),
            ]);
        } else {
            Log::info("No meta data to add", ['product_id' => $product->id]);
        }
        
        // For variable products, also set weight on the main product
        if ( $hasVariations ) {
            $weight = $this->weightDetector->detectWeightFromName($product->title);
            $payload['weight'] = (string) $weight;
            Log::info("Weight set for variable product", [
                'product_id' => $product->id,
                'weight' => $weight,
            ]);
        }

        Log::info("Product payload built successfully", [
            'product_id' => $product->id,
            'payload_size' => count($payload),
        ]);

        return $payload;
    }

    /**
     * Calculate final price based on discount, profit, tax, and multiplier
     * 
     * @param float $originalPrice Original product price
     * @param Product $product Product model to get discount from meta
     * @return int Final price rounded to nearest integer
     */
    protected function calculateFinalPrice(float $originalPrice, Product $product): int
    {
        // Get discount from meta field (default to 0 if not found)
        $discount = 0;
        if ($product->meta && is_array($product->meta) && isset($product->meta['discount'])) {
            $discount = (int) $product->meta['discount'];
            // Ensure discount is between 1 and 99
            $discount = max(1, min(99, $discount));
        }
        
        // Calculate profit based on discount tier
        $profit = 0;
        if ($discount >= 1 && $discount <= 30) {
            $profit = $originalPrice * 0.21;
        } elseif ($discount >= 31 && $discount <= 60) {
            $profit = $originalPrice * 0.27;
        } elseif ($discount >= 61 && $discount <= 99) {
            $profit = $originalPrice; // 100% markup
        }
        
        // Calculate 13% tax based on original price
        $tax = $originalPrice * 0.13;
        
        // Calculate final price: (price + profit + tax) * 150000
        $finalPrice = ($originalPrice + $profit + $tax) * 150000;
        
        // Round to nearest integer
        return (int) round($finalPrice);
    }

    /**
     * Map local status to WooCommerce status
     */
    protected function mapStatus (string $status) : string {
        $statusMap = [
            'published' => 'publish',
            'active'    => 'publish',
            'draft'     => 'draft',
            'archived'  => 'private',
        ];

        return $statusMap[$status] ?? 'draft';
    }

    /**
     * Resolve a stored path value to an absolute local file path
     * Handles relative paths, full Windows paths, and normalizes slashes
     *
     * @param string $storedValue The path value stored in database (could be relative or absolute)
     * @return string|null Absolute file path if file exists and is readable, null otherwise
     */
    protected function resolveLocalImagePath (string $storedValue) : ?string {
        if ( empty($storedValue) ) {
            return null;
        }

        // Normalize slashes (handle Windows backslashes)
        $normalized = str_replace('\\', '/', trim($storedValue));
        
        // Remove leading "/storage/" or "/public/" if present
        $normalized = preg_replace('#^/?storage/#', '', $normalized);
        $normalized = preg_replace('#^/?public/#', '', $normalized);
        $normalized = ltrim($normalized, '/');

        // Check if it's already an absolute path
        if ( file_exists($storedValue) && is_readable($storedValue) ) {
            return realpath($storedValue) ?: $storedValue;
        }

        // Try as absolute Windows path (C:\... or C:/...)
        // Check if it starts with drive letter and colon, followed by slash or backslash
        if ( preg_match('#^[A-Za-z]:#', $storedValue) ) {
            $thirdChar = substr($storedValue, 2, 1);
            if ( $thirdChar === '/' || $thirdChar === '\\' ) {
                $absPath = str_replace('/', DIRECTORY_SEPARATOR, $storedValue);
                if ( file_exists($absPath) && is_readable($absPath) ) {
                    return realpath($absPath) ?: $absPath;
                }
            }
        }

        // Try with storage_path('app/public/...')
        $storagePath = storage_path('app/public/' . $normalized);
        if ( file_exists($storagePath) && is_readable($storagePath) ) {
            return realpath($storagePath) ?: $storagePath;
        }

        // Try direct path if normalized value is a valid path
        if ( file_exists($normalized) && is_readable($normalized) ) {
            return realpath($normalized) ?: $normalized;
        }

        Log::warning("Could not resolve local image path", [
            'stored_value' => $storedValue,
            'normalized' => $normalized,
            'storage_path' => $storagePath,
        ]);

        return null;
    }

    /**
     * Build images array for product payload
     * Uploads local images to WordPress Media Library and returns media IDs
     */
    protected function buildImagesArray (Product $product) : array {
        $images = [];

        // Sort media: primary first, then by order
        $mediaItems = $product->media()->orderBy('is_primary', 'desc')->orderBy('id')->get();

        Log::info("Building images array - uploading to WordPress Media Library", [
            'product_id' => $product->id,
            'media_count' => $mediaItems->count(),
        ]);

        foreach ( $mediaItems as $media ) {
            $localPath = null;

            // Priority 1: Use local_path if available
            if ( !empty($media->local_path) ) {
                $localPath = $this->resolveLocalImagePath($media->local_path);
                if ( $localPath ) {
                    Log::debug("Resolved local_path for image", [
                        'product_id' => $product->id,
                        'media_id' => $media->id,
                        'stored_path' => $media->local_path,
                        'resolved_path' => $localPath,
                    ]);
                }
            }

            // Priority 2: Fallback to source_url if local_path not available or not found
            if ( !$localPath && !empty($media->source_url) ) {
                // Only use source_url if it's NOT a full URL (external URLs won't work)
                if ( !filter_var($media->source_url, FILTER_VALIDATE_URL) ) {
                    $localPath = $this->resolveLocalImagePath($media->source_url);
                    if ( $localPath ) {
                        Log::debug("Resolved source_url for image", [
                            'product_id' => $product->id,
                            'media_id' => $media->id,
                            'stored_path' => $media->source_url,
                            'resolved_path' => $localPath,
                        ]);
                    }
                }
            }

            // Upload image if we have a valid local path
            if ( $localPath ) {
                try {
                    $uploadedMedia = $this->apiClient->uploadMediaFromLocalPath($localPath, $media->alt_text ?? '');
                    
                    if ( $uploadedMedia && isset($uploadedMedia['id']) ) {
                        $images[] = [
                            'id' => $uploadedMedia['id'],
                        ];
                        
                        Log::info("Image uploaded and added to payload", [
                            'product_id' => $product->id,
                            'media_id' => $media->id,
                            'local_path' => $localPath,
                            'wordpress_media_id' => $uploadedMedia['id'],
                        ]);
                    } else {
                        Log::warning("Failed to upload image, skipping", [
                            'product_id' => $product->id,
                            'media_id' => $media->id,
                            'local_path' => $localPath,
                        ]);
                    }
                } catch ( \Exception $e ) {
                    Log::error("Exception while uploading image, continuing with next image", [
                        'product_id' => $product->id,
                        'media_id' => $media->id,
                        'local_path' => $localPath,
                        'error' => $e->getMessage(),
                    ]);
                    // Continue with next image instead of failing entire product
                }
            } else {
                Log::warning("Skipping media item - no valid local file path found", [
                    'product_id' => $product->id,
                    'media_id' => $media->id,
                    'has_local_path' => !empty($media->local_path),
                    'local_path_value' => $media->local_path ?? null,
                    'has_source_url' => !empty($media->source_url),
                    'source_url_value' => $media->source_url ?? null,
                ]);
            }
        }

        Log::info("Images array built", [
            'product_id' => $product->id,
            'image_count' => count($images),
            'media_items_processed' => $mediaItems->count(),
        ]);

        // Only fail if ALL images failed (no images at all)
        if ( $mediaItems->count() > 0 && count($images) === 0 ) {
            Log::warning("All images failed to upload for product", [
                'product_id' => $product->id,
                'media_count' => $mediaItems->count(),
            ]);
        }

        return $images;
    }

    /**
     * Get category IDs for the product
     */
    protected function getCategoryIds (Product $product) : array {
        $categoryIds = [];

        // Check if there's a default category configured
        $defaultCategory = config('wordpress.default_category');
        if ( $defaultCategory ) {
            // Try to find or create the category
            $category = $this->apiClient->getCategoryByName($defaultCategory);
            if ( !$category ) {
                try {
                    $category = $this->apiClient->createCategory($defaultCategory);
                } catch ( Exception $e ) {
                    Log::warning("Failed to create default category", [
                        'category' => $defaultCategory,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }

            if ( $category ) {
                $categoryIds[] = ['id' => $category['id']];
            }
        }

        // You could also check product attributes for category info
        // or use website name as category
        if ( empty($categoryIds) ) {
            $website = $product->website;
            if ( $website && $website->name ) {
                $websiteName = $website->name;
                $category    = $this->apiClient->getCategoryByName($websiteName);
                if ( !$category ) {
                    try {
                        $category = $this->apiClient->createCategory($websiteName);
                    } catch ( Exception $e ) {
                        Log::warning("Failed to create website category", [
                            'category' => $websiteName,
                            'error'    => $e->getMessage(),
                        ]);
                    }
                }

                if ( $category ) {
                    $categoryIds[] = ['id' => $category['id']];
                }
            }
        }

        return $categoryIds;
    }

    /**
     * Build meta_data array from product attributes
     */
    protected function buildMetaData (Product $product) : array {
        $metaData = [];

        foreach ( $product->attributes as $attribute ) {
            // Skip variant_matrix as it's used for variations, not as meta
            if ( $attribute->name === 'variant_matrix' ) {
                continue;
            }

            // Include other attributes as meta
            $metaData[] = [
                'key'   => $attribute->name,
                'value' => $attribute->value,
            ];
        }

        // Add weblink from raw_data.url if available
        if ( $product->raw_data && is_array($product->raw_data) && !empty($product->raw_data['url']) ) {
            $metaData[] = [
                'key'   => 'weblink',
                'value' => $product->raw_data['url'],
            ];
        }

        // Add _weblink field (constant value)
        $metaData[] = [
            'key'   => '_weblink',
            'value' => 'field_68886610d7e93',
        ];

        return $metaData;
    }

    /**
     * Create all variations for a variable product
     */
    protected function createVariations (int $wooProductId, Product $product, array $variantMatrix) : void {
        Log::info("Creating variations for product", [
            'product_id'     => $product->id,
            'woo_product_id' => $wooProductId,
            'colourway_count' => count($variantMatrix),
        ]);

        $colorAttributeId = $this->attributeService->getColorAttributeId();
        $sizeAttributeId  = $this->attributeService->getSizeAttributeId();

        Log::info("Attribute IDs retrieved", [
            'product_id' => $product->id,
            'color_attribute_id' => $colorAttributeId,
            'size_attribute_id' => $sizeAttributeId,
        ]);

        $variationCount = 0;
        $failedCount = 0;
        $totalVariants = 0;

        // Count total variants first
        foreach ( $variantMatrix as $colourway ) {
            $variants = $colourway['variants'] ?? [];
            $totalVariants += count($variants);
        }

        Log::info("Processing variations", [
            'product_id' => $product->id,
            'total_variants_to_create' => $totalVariants,
        ]);

        // Cache for colour image media IDs (upload once per colour)
        $colourImageMediaIdCache = [];

        foreach ( $variantMatrix as $colourway ) {
            $colorLabel = $colourway['colour_label'] ?? $colourway['colour'] ?? null;
            if ( !$colorLabel ) {
                Log::warning("Skipping colourway with no color label", [
                    'product_id' => $product->id,
                    'colourway' => $colourway,
                ]);
                continue;
            }

            $variants = $colourway['variants'] ?? [];
            Log::info("Processing colourway", [
                'product_id' => $product->id,
                'color' => $colorLabel,
                'variant_count' => count($variants),
            ]);

            // Process colourway image (upload once per colour)
            $cacheKey = mb_strtolower(trim($colorLabel));
            if ( !isset($colourImageMediaIdCache[$cacheKey]) ) {
                $firstLocalRel = $colourway['images_local'][0] ?? null;
                if ( $firstLocalRel ) {
                    $abs = $this->resolveLocalImagePath($firstLocalRel);
                    if ( $abs && file_exists($abs) ) {
                        try {
                            $altText = $product->title . ' - ' . $colorLabel;
                            $uploaded = $this->apiClient->uploadMediaFromLocalPath($abs, $altText);
                            $mediaId = $uploaded['id'] ?? null;
                            if ( $mediaId ) {
                                $colourImageMediaIdCache[$cacheKey] = $mediaId;
                                Log::info("Uploaded colour image for variation", [
                                    'product_id' => $product->id,
                                    'color' => $colorLabel,
                                    'media_id' => $mediaId,
                                    'local_path' => $firstLocalRel,
                                ]);
                            }
                        } catch ( Exception $e ) {
                            Log::warning("Failed to upload colour image for variation", [
                                'product_id' => $product->id,
                                'color' => $colorLabel,
                                'local_path' => $firstLocalRel,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    } else {
                        Log::warning("No variation image found for colour - file does not exist", [
                            'product_id' => $product->id,
                            'color' => $colorLabel,
                            'local_path' => $firstLocalRel,
                        ]);
                    }
                } else {
                    Log::warning("No variation image found for colour - images_local is empty", [
                        'product_id' => $product->id,
                        'color' => $colorLabel,
                    ]);
                }
            }

            foreach ( $variants as $variant ) {
                $size = $variant['size'] ?? null;
                if ( !$size ) {
                    Log::warning("Skipping variant with no size", [
                        'product_id' => $product->id,
                        'color' => $colorLabel,
                        'variant' => $variant,
                    ]);
                    continue;
                }

                Log::info("Creating variation", [
                    'product_id' => $product->id,
                    'woo_product_id' => $wooProductId,
                    'color' => $colorLabel,
                    'size' => $size,
                ]);

                // Build variation payload
                $variationData = [
                    'attributes' => [
                        [
                            'id'     => $colorAttributeId,
                            'option' => $colorLabel,
                        ],
                        [
                            'id'     => $sizeAttributeId,
                            'option' => $size,
                        ],
                    ],
                ];

                // Add price if available (with calculation)
                $price = $variant['price'] ?? $colourway['base_price'] ?? $product->price;
                if ( $price ) {
                    $calculatedPrice = $this->calculateFinalPrice($price, $product);
                    $variationData['regular_price'] = (string) $calculatedPrice;
                }
                
                // Detect and set weight
                $weight = $this->weightDetector->detectWeightFromName($product->title);
                $variationData['weight'] = (string) $weight;

                // Add SKU
                $sku = $variant['sku'] ?? null;
                if ( $sku ) {
                    $variationData['sku'] = (string) $sku;
                } elseif ( $product->external_id ) {
                    // Generate SKU from product SKU + color + size
                    $variationData['sku'] = sprintf('%s-%s-%s', $product->external_id, strtoupper(substr($colorLabel, 0, 3)), $size);
                }

                // Add stock management
                $stockAvailable                  = $variant['stock_availability'] ?? false;
                $variationData['manage_stock']   = true;
                $variationData['stock_quantity'] = $stockAvailable ? 1 : 0; // Default to 1 if available, 0 if not
                $variationData['stock_status']   = $stockAvailable ? 'instock' : 'outofstock';

                // Add variation image if available
                if ( isset($colourImageMediaIdCache[$cacheKey]) ) {
                    $variationData['image'] = ['id' => $colourImageMediaIdCache[$cacheKey]];
                    Log::debug("Assigned variation image", [
                        'product_id' => $product->id,
                        'color' => $colorLabel,
                        'size' => $size,
                        'media_id' => $colourImageMediaIdCache[$cacheKey],
                    ]);
                }

                try {
                    $response = $this->apiClient->createVariation($wooProductId, $variationData);
                    $variationCount++;
                    Log::info("Variation created successfully", [
                        'product_id' => $product->id,
                        'woo_product_id' => $wooProductId,
                        'variation_id' => $response['id'] ?? null,
                        'color' => $colorLabel,
                        'size' => $size,
                        'sku' => $variationData['sku'] ?? null,
                    ]);
                } catch ( Exception $e ) {
                    $failedCount++;
                    Log::error("Failed to create variation", [
                        'product_id'     => $product->id,
                        'woo_product_id' => $wooProductId,
                        'color'          => $colorLabel,
                        'size'           => $size,
                        'error'          => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::info("Variation creation completed", [
            'product_id'     => $product->id,
            'woo_product_id' => $wooProductId,
            'created'        => $variationCount,
            'failed'         => $failedCount,
            'total'          => $totalVariants,
        ]);
    }

    /**
     * Save product mapping to database
     */
    protected function saveMapping (Product $product, int $wooProductId, string $status, array $payload) : void {
        $mapping                       = $product->wordpressMapping()->firstOrNew();
        $mapping->product_id           = $product->id;
        $mapping->wordpress_product_id = $wooProductId;
        $mapping->wordpress_site_url   = config('wordpress.base_url');
        $mapping->last_synced_at       = now();
        $mapping->last_sync_status     = $status;
        $mapping->last_sync_payload    = $payload;
            $mapping->save();
    }

    /**
     * Update an existing product in WooCommerce
     */
    protected function updateProduct (
        Product $product, ProductWordpressMapping $mapping, ?array $variantMatrix, bool $hasVariations
    ) : void {
        $wooProductId = $mapping->wordpress_product_id;

        Log::info("Step 1: Building update payload", [
            'product_id' => $product->id,
            'woo_product_id' => $wooProductId,
            'type' => $hasVariations ? 'variable' : 'simple',
        ]);

        // Build product payload
        $productData = $this->buildProductPayload($product, $variantMatrix, $hasVariations);

        Log::info("Step 2: Update payload built", [
            'product_id' => $product->id,
            'woo_product_id' => $wooProductId,
            'payload_keys' => array_keys($productData),
            'has_images' => !empty($productData['images']),
            'image_count' => count($productData['images'] ?? []),
        ]);

        Log::info("Step 3: Updating product via WooCommerce API", [
            'product_id' => $product->id,
            'woo_product_id' => $wooProductId,
        ]);

        // Update the product
        $response = $this->apiClient->upsertProduct($wooProductId, $productData);

        Log::info("Step 4: Product updated in WooCommerce", [
            'product_id' => $product->id,
            'woo_product_id' => $wooProductId,
        ]);

        // Sync variations if this is a variable product
        if ( $hasVariations && !empty($variantMatrix) ) {
            Log::info("Step 5: Syncing variations", [
                'product_id' => $product->id,
                'woo_product_id' => $wooProductId,
            ]);
            $this->syncVariations($wooProductId, $product, $variantMatrix);
        } else {
            Log::info("Step 5: Skipping variation sync (simple product)", [
                'product_id' => $product->id,
                'woo_product_id' => $wooProductId,
            ]);
        }

        Log::info("Step 6: Updating product mapping in database", [
            'product_id' => $product->id,
            'woo_product_id' => $wooProductId,
        ]);

        // Save mapping
        $this->saveMapping($product, $wooProductId, 'success', $response);

        Log::info("Product update completed successfully", [
            'product_id'     => $product->id,
            'woo_product_id' => $wooProductId,
            'product_type' => $hasVariations ? 'variable' : 'simple',
        ]);
    }

    /**
     * Sync variations for an existing variable product
     */
    protected function syncVariations (int $wooProductId, Product $product, array $variantMatrix) : void {
        Log::info("Starting variation sync", [
            'product_id'     => $product->id,
            'woo_product_id' => $wooProductId,
            'colourway_count' => count($variantMatrix),
        ]);

        Log::info("Fetching existing variations from WooCommerce", [
            'product_id' => $product->id,
            'woo_product_id' => $wooProductId,
        ]);

        // Get existing variations from WooCommerce
        $existingVariations = $this->apiClient->getProductVariations($wooProductId);

        Log::info("Existing variations retrieved", [
            'product_id' => $product->id,
            'woo_product_id' => $wooProductId,
            'existing_count' => count($existingVariations),
        ]);

        // Build a map of existing variations by attribute combination
        $existingVariationMap = [];
        foreach ( $existingVariations as $existingVar ) {
            $key = $this->getVariationKey($existingVar);
            if ( $key ) {
                $existingVariationMap[$key] = $existingVar;
            }
        }

        Log::info("Existing variations mapped", [
            'product_id' => $product->id,
            'mapped_count' => count($existingVariationMap),
        ]);

        $colorAttributeId = $this->attributeService->getColorAttributeId();
        $sizeAttributeId  = $this->attributeService->getSizeAttributeId();

        Log::info("Attribute IDs retrieved for variation sync", [
            'product_id' => $product->id,
            'color_attribute_id' => $colorAttributeId,
            'size_attribute_id' => $sizeAttributeId,
        ]);

        $created = 0;
        $updated = 0;
        $failed = 0;
        $totalVariants = 0;

        // Count total variants
        foreach ( $variantMatrix as $colourway ) {
            $variants = $colourway['variants'] ?? [];
            $totalVariants += count($variants);
        }

        Log::info("Processing variations for sync", [
            'product_id' => $product->id,
            'total_variants_to_process' => $totalVariants,
        ]);

        // Cache for colour image media IDs (upload once per colour)
        $colourImageMediaIdCache = [];

        // Process each variant from our data
        foreach ( $variantMatrix as $colourway ) {
            $colorLabel = $colourway['colour_label'] ?? $colourway['colour'] ?? null;
            if ( !$colorLabel ) {
                Log::warning("Skipping colourway with no color label during sync", [
                    'product_id' => $product->id,
                ]);
                continue;
            }

            $variants = $colourway['variants'] ?? [];
            Log::info("Processing colourway for sync", [
                'product_id' => $product->id,
                'color' => $colorLabel,
                'variant_count' => count($variants),
            ]);

            // Process colourway image (upload once per colour)
            $cacheKey = mb_strtolower(trim($colorLabel));
            if ( !isset($colourImageMediaIdCache[$cacheKey]) ) {
                $firstLocalRel = $colourway['images_local'][0] ?? null;
                if ( $firstLocalRel ) {
                    $abs = $this->resolveLocalImagePath($firstLocalRel);
                    if ( $abs && file_exists($abs) ) {
                        try {
                            $altText = $product->title . ' - ' . $colorLabel;
                            $uploaded = $this->apiClient->uploadMediaFromLocalPath($abs, $altText);
                            $mediaId = $uploaded['id'] ?? null;
                            if ( $mediaId ) {
                                $colourImageMediaIdCache[$cacheKey] = $mediaId;
                                Log::info("Uploaded colour image for variation", [
                                    'product_id' => $product->id,
                                    'color' => $colorLabel,
                                    'media_id' => $mediaId,
                                    'local_path' => $firstLocalRel,
                                ]);
                            }
                        } catch ( Exception $e ) {
                            Log::warning("Failed to upload colour image for variation", [
                                'product_id' => $product->id,
                                'color' => $colorLabel,
                                'local_path' => $firstLocalRel,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    } else {
                        Log::warning("No variation image found for colour - file does not exist", [
                            'product_id' => $product->id,
                            'color' => $colorLabel,
                            'local_path' => $firstLocalRel,
                        ]);
                    }
                } else {
                    Log::warning("No variation image found for colour - images_local is empty", [
                        'product_id' => $product->id,
                        'color' => $colorLabel,
                    ]);
                }
            }

            foreach ( $variants as $variant ) {
                $size = $variant['size'] ?? null;
                if ( !$size ) {
                    Log::warning("Skipping variant with no size during sync", [
                        'product_id' => $product->id,
                        'color' => $colorLabel,
                    ]);
                    continue;
                }

                $variationKey  = "{$colorLabel}|{$size}";
                $variationData = $this->buildVariationData($product, $colourway, $variant, $colorAttributeId, $sizeAttributeId);

                // Add variation image if available
                if ( isset($colourImageMediaIdCache[$cacheKey]) ) {
                    $variationData['image'] = ['id' => $colourImageMediaIdCache[$cacheKey]];
                    Log::debug("Assigned variation image", [
                        'product_id' => $product->id,
                        'color' => $colorLabel,
                        'size' => $size,
                        'media_id' => $colourImageMediaIdCache[$cacheKey],
                    ]);
                }

                if ( isset($existingVariationMap[$variationKey]) ) {
                    // Update existing variation
                    $existingVar = $existingVariationMap[$variationKey];
                    Log::info("Updating existing variation", [
                        'product_id' => $product->id,
                        'woo_product_id' => $wooProductId,
                        'variation_id' => $existingVar['id'],
                        'color' => $colorLabel,
                        'size' => $size,
                    ]);

                    try {
                        $response = $this->apiClient->updateVariation($wooProductId, $existingVar['id'], $variationData);
                        $updated++;
                        Log::info("Variation updated successfully", [
                            'product_id' => $product->id,
                            'variation_id' => $existingVar['id'],
                            'color' => $colorLabel,
                            'size' => $size,
                        ]);
                    } catch ( Exception $e ) {
                        $failed++;
                        Log::error("Failed to update variation", [
                            'product_id'   => $product->id,
                            'variation_id' => $existingVar['id'],
                            'color' => $colorLabel,
                            'size' => $size,
                            'error'        => $e->getMessage(),
                        ]);
                    }
                    unset($existingVariationMap[$variationKey]);
                } else {
                    // Create new variation
                    Log::info("Creating new variation", [
                        'product_id' => $product->id,
                        'woo_product_id' => $wooProductId,
                        'color' => $colorLabel,
                        'size' => $size,
                    ]);

                    try {
                        $response = $this->apiClient->createVariation($wooProductId, $variationData);
                        $created++;
                        Log::info("Variation created successfully", [
                            'product_id' => $product->id,
                            'woo_product_id' => $wooProductId,
                            'variation_id' => $response['id'] ?? null,
                            'color' => $colorLabel,
                            'size' => $size,
                        ]);
                    } catch ( Exception $e ) {
                        $failed++;
                        Log::error("Failed to create variation", [
                'product_id' => $product->id,
                            'woo_product_id' => $wooProductId,
                            'color' => $colorLabel,
                            'size' => $size,
                            'error'      => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        // Optionally delete variations that no longer exist locally
        // (Uncomment if you want to delete removed variations)
        /*
        foreach ($existingVariationMap as $variation) {
            try {
                $this->apiClient->deleteVariation($wooProductId, $variation['id']);
                Log::info("Deleted variation that no longer exists locally", [
                    'variation_id' => $variation['id'],
            ]);
        } catch (\Exception $e) {
                Log::error("Failed to delete variation", [
                    'variation_id' => $variation['id'],
                'error' => $e->getMessage(),
                ]);
            }
        }
        */

        Log::info("Variation sync completed", [
            'product_id'     => $product->id,
            'woo_product_id' => $wooProductId,
            'created'        => $created,
            'updated'        => $updated,
            'failed'         => $failed,
            'total_processed' => $totalVariants,
        ]);
    }

    /**
     * Get a unique key for a variation based on its attributes
     */
    protected function getVariationKey (array $variation) : ?string {
        $attributes = $variation['attributes'] ?? [];
        $color      = null;
        $size       = null;

        foreach ( $attributes as $attr ) {
            $attrName  = strtolower($attr['name'] ?? '');
            $attrValue = $attr['option'] ?? '';

            if ( $attrName === 'color' || $attrName === 'colour' ) {
                $color = $attrValue;
            } elseif ( $attrName === 'size' ) {
                $size = $attrValue;
            }
        }

        if ( $color && $size ) {
            return "{$color}|{$size}";
        }

        return null;
    }

    /**
     * Build variation data payload
     */
    protected function buildVariationData (
        Product $product, array $colourway, array $variant, int $colorAttributeId, int $sizeAttributeId
    ) : array {
        $colorLabel = $colourway['colour_label'] ?? $colourway['colour'] ?? null;
        $size       = $variant['size'] ?? null;

        $variationData = [
            'attributes' => [
                [
                    'id'     => $colorAttributeId,
                    'option' => $colorLabel,
                ],
                [
                    'id'     => $sizeAttributeId,
                    'option' => $size,
                ],
            ],
        ];

        // Add price (with calculation)
        $price = $variant['price'] ?? $colourway['base_price'] ?? $product->price;
        if ( $price ) {
            $calculatedPrice = $this->calculateFinalPrice($price, $product);
            $variationData['regular_price'] = (string) $calculatedPrice;
        }
        
        // Detect and set weight
        $weight = $this->weightDetector->detectWeightFromName($product->title);
        $variationData['weight'] = (string) $weight;

        // Add SKU
        $sku = $variant['sku'] ?? null;
        if ( $sku ) {
            $variationData['sku'] = (string) $sku;
        } elseif ( $product->external_id ) {
            $variationData['sku'] = sprintf('%s-%s-%s', $product->external_id, strtoupper(substr($colorLabel, 0, 3)), $size);
        }

        // Add stock
        $stockAvailable                  = $variant['stock_availability'] ?? false;
        $variationData['manage_stock']   = true;
        $variationData['stock_quantity'] = $stockAvailable ? 1 : 0;
        $variationData['stock_status']   = $stockAvailable ? 'instock' : 'outofstock';

        return $variationData;
    }

    /**
     * Handle sync errors
     */
    protected function handleSyncError (Product $product, Exception $e) : void {
        Log::error("Handling sync error - saving failed status to database", [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);

        $mapping                     = $product->wordpressMapping()->firstOrNew();
        $mapping->product_id         = $product->id;
        $mapping->wordpress_site_url = config('wordpress.base_url');
        $mapping->last_synced_at     = now();
        $mapping->last_sync_status   = 'failed';
        $mapping->last_sync_payload  = [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ];
        $mapping->save();

        Log::error("Failed status saved to database", [
            'product_id' => $product->id,
            'mapping_id' => $mapping->id,
        ]);
    }
}
