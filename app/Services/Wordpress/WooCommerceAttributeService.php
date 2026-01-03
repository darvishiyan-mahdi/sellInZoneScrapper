<?php

namespace App\Services\Wordpress;

use Illuminate\Support\Facades\Log;

/**
 * WooCommerce Attribute Service
 *
 * Manages global product attributes and their terms in WooCommerce.
 * Ensures attributes exist before creating products with variations.
 */
class WooCommerceAttributeService {
    protected WooCommerceApiClient $apiClient;

    /**
     * Cache of attribute IDs by name
     *
     * @var array<string, int>
     */
    protected array $attributeCache = [];

    /**
     * Cache of term IDs by attribute ID and term name
     *
     * @var array<int, array<string, int>>
     */
    protected array $termCache = [];

    public function __construct (WooCommerceApiClient $apiClient) {
        $this->apiClient = $apiClient;
    }

    /**
     * Prepare attributes for a variable product.
     *
     * @param array $variantData Array of variant data (e.g., from variant_matrix)
     *
     * @return array Array of attribute configurations for WooCommerce product payload
     */
    public function prepareProductAttributes (array $variantData) : array {
        $attributeMap = [];

        // Extract all unique attribute values from variants
        // Assuming variant structure: [['colour_label' => 'Black', 'variants' => [['size' => 'S'], ...]], ...]
        $colors = [];
        $sizes  = [];

        foreach ( $variantData as $colourway ) {
            $colorLabel = $colourway['colour_label'] ?? $colourway['colour'] ?? null;
            if ( $colorLabel ) {
                $colors[] = $colorLabel;
            }

            $variants = $colourway['variants'] ?? [];
            foreach ( $variants as $variant ) {
                $size = $variant['size'] ?? null;
                if ( $size ) {
                    $sizes[] = $size;
                }
            }
        }

        $colors = array_unique($colors);
        $sizes  = array_unique($sizes);

        $productAttributes = [];
        $position          = 0;

        // Setup Color attribute
        if ( !empty($colors) ) {
            $colorAttributeId = $this->getOrCreateAttribute('Color');
            $colorTerms       = $this->ensureAttributeTerms($colorAttributeId, $colors);

            $productAttributes[] = [
                'id'        => $colorAttributeId,
                'position'  => $position++,
                'visible'   => true,
                'variation' => true,
                'options'   => $colorTerms,
            ];
        }

        // Setup Size attribute
        if ( !empty($sizes) ) {
            $sizeAttributeId = $this->getOrCreateAttribute('Size');
            $sizeTerms       = $this->ensureAttributeTerms($sizeAttributeId, $sizes);

            $productAttributes[] = [
                'id'        => $sizeAttributeId,
                'position'  => $position++,
                'visible'   => true,
                'variation' => true,
                'options'   => $sizeTerms,
            ];
        }

        return $productAttributes;
    }

    /**
     * Get or create an attribute in WooCommerce.
     * Checks for existing attribute by slug (preferred) or name (fallback) before creating.
     * Returns the attribute ID.
     *
     * @param string $name Attribute name
     * @param string|null $slug Optional slug (auto-generated from name if not provided)
     * @return int Attribute ID
     */
    public function getOrCreateAttribute (string $name, ?string $slug = null) : int {
        return $this->ensureAttribute($name, $slug);
    }

    /**
     * Get or create an attribute term in WooCommerce.
     * Checks for existing term by slug (preferred) or name (fallback) before creating.
     * Returns the term name (WooCommerce uses term names in variation attributes).
     *
     * @param int $attributeId Attribute ID
     * @param string $termName Term name
     * @return string Term name
     */
    public function getOrCreateAttributeTerm (int $attributeId, string $termName) : string {
        return $this->ensureAttributeTerm($attributeId, $termName);
    }

    /**
     * Ensure an attribute exists in WooCommerce, creating it if necessary.
     * Returns the attribute ID.
     *
     * @internal Use getOrCreateAttribute() instead
     */
    protected function ensureAttribute (string $attributeName, ?string $slug = null) : int {
        // Step 1: Check cache first (fastest)
        if ( isset($this->attributeCache[$attributeName]) ) {
            Log::debug("Attribute found in cache", [
                'name' => $attributeName,
                'id'   => $this->attributeCache[$attributeName],
            ]);
            return $this->attributeCache[$attributeName];
        }

        // Step 2: Generate slug from name if not provided (WooCommerce uses slug as unique identifier)
        if ( $slug === null ) {
            $slug = $this->generateSlug($attributeName);
        }
        
        Log::info("Checking for existing attribute before creation", [
            'name' => $attributeName,
            'slug' => $slug,
        ]);

        // Step 3: Check by slug first (most reliable - WooCommerce uses slug as unique key)
        $attribute = $this->apiClient->getAttributeBySlug($slug);
        if ( $attribute ) {
            $attributeId                          = $attribute['id'];
            $this->attributeCache[$attributeName] = $attributeId;
            Log::info("✓ Found existing WooCommerce attribute by slug", [
                'name' => $attributeName,
                'slug' => $slug,
                'id'   => $attributeId,
                'existing_name' => $attribute['name'] ?? null,
            ]);
            return $attributeId;
        }

        // Step 4: Fallback - check by name (case-insensitive)
        $attribute = $this->apiClient->getAttributeByName($attributeName);
        if ( $attribute ) {
            $attributeId                          = $attribute['id'];
            $this->attributeCache[$attributeName] = $attributeId;
            Log::info("✓ Found existing WooCommerce attribute by name", [
                'name' => $attributeName,
                'slug' => $attribute['slug'] ?? null,
                'id'   => $attributeId,
            ]);
            return $attributeId;
        }

        // Step 5: Double-check by slug one more time (handle any race conditions)
        // This ensures we don't create duplicates if something was created between checks
        $attribute = $this->apiClient->getAttributeBySlug($slug);
        if ( $attribute ) {
            $attributeId                          = $attribute['id'];
            $this->attributeCache[$attributeName] = $attributeId;
            Log::info("✓ Found existing WooCommerce attribute on second slug check", [
                'name' => $attributeName,
                'slug' => $slug,
                'id'   => $attributeId,
            ]);
            return $attributeId;
        }

        // Step 6: Only create if we've confirmed it doesn't exist
        Log::info("Attribute does not exist, creating new WooCommerce attribute", [
            'name' => $attributeName,
            'slug' => $slug,
        ]);

        try {
            $attribute = $this->apiClient->createAttribute($attributeName, [
                'slug'     => $slug,
                'type'     => 'select',
                'order_by' => 'menu_order',
            ]);

            $attributeId                          = $attribute['id'];
            $this->attributeCache[$attributeName] = $attributeId;

            Log::info("✓ Created new WooCommerce attribute", [
                'name' => $attributeName,
                'slug' => $slug,
                'id'   => $attributeId,
            ]);

            return $attributeId;
        } catch ( \Exception $e ) {
            // Step 7: If creation fails (e.g., slug conflict), check one final time
            // This handles race conditions where attribute was created between our checks
            Log::warning("Creation failed, performing final check for existing attribute", [
                'name' => $attributeName,
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);

            $attribute = $this->apiClient->getAttributeBySlug($slug);
            if ( $attribute ) {
                $attributeId                          = $attribute['id'];
                $this->attributeCache[$attributeName] = $attributeId;
                Log::info("✓ Found existing WooCommerce attribute after creation failure", [
                    'name' => $attributeName,
                    'slug' => $slug,
                    'id'   => $attributeId,
                ]);
                return $attributeId;
            }

            // Re-throw if we still can't find it after all checks
            Log::error("Failed to create or find attribute after all checks", [
                'name' => $attributeName,
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Generate slug from attribute name
     */
    protected function generateSlug (string $name) : string {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($name)));
        return trim($slug, '-');
    }

    /**
     * Ensure multiple terms exist for an attribute.
     * Returns array of term names.
     *
     * @internal Use getOrCreateAttributeTerm() for single terms
     */
    protected function ensureAttributeTerms (int $attributeId, array $termNames) : array {
        $ensuredTerms = [];
        foreach ( $termNames as $termName ) {
            $ensuredTerms[] = $this->ensureAttributeTerm($attributeId, $termName);
        }

        return $ensuredTerms;
    }

    /**
     * Ensure a term exists for an attribute, creating it if necessary.
     * Returns the term name (WooCommerce uses term names, not IDs, in variation attributes).
     */
    protected function ensureAttributeTerm (int $attributeId, string $termName) : string {
        // Initialize cache for this attribute if needed
        if ( !isset($this->termCache[$attributeId]) ) {
            $this->termCache[$attributeId] = [];
        }

        // Check cache first
        if ( isset($this->termCache[$attributeId][$termName]) ) {
            Log::debug("Attribute term found in cache", [
                'attribute_id' => $attributeId,
                'term_name'    => $termName,
                'term_id'      => $this->termCache[$attributeId][$termName],
            ]);
            return $termName; // WooCommerce uses term names in variations
        }

        // Generate slug from term name
        $slug = $this->generateSlug($termName);

        Log::info("Checking for existing attribute term before creation", [
            'attribute_id' => $attributeId,
            'term_name'    => $termName,
            'slug'         => $slug,
        ]);

        // Step 1: Check by slug first (preferred - WooCommerce uses slug as unique key)
        $term = $this->apiClient->getAttributeTermBySlug($attributeId, $slug);
        if ( $term ) {
            $termId = $term['id'];
            $this->termCache[$attributeId][$termName] = $termId;
            Log::info("✓ Found existing WooCommerce attribute term by slug", [
                'attribute_id' => $attributeId,
                'term_name'    => $termName,
                'slug'         => $slug,
                'term_id'      => $termId,
                'existing_name' => $term['name'] ?? null,
            ]);
            return $termName;
        }

        // Step 2: Fallback - check by name (case-insensitive)
        $term = $this->apiClient->getAttributeTermByName($attributeId, $termName);
        if ( $term ) {
            $termId = $term['id'];
            $this->termCache[$attributeId][$termName] = $termId;
            Log::info("✓ Found existing WooCommerce attribute term by name", [
                'attribute_id' => $attributeId,
                'term_name'    => $termName,
                'slug'         => $term['slug'] ?? null,
                'term_id'      => $termId,
            ]);
            return $termName;
        }

        // Step 3: Double-check by slug one more time (handle any race conditions)
        $term = $this->apiClient->getAttributeTermBySlug($attributeId, $slug);
        if ( $term ) {
            $termId = $term['id'];
            $this->termCache[$attributeId][$termName] = $termId;
            Log::info("✓ Found existing WooCommerce attribute term on second slug check", [
                'attribute_id' => $attributeId,
                'term_name'    => $termName,
                'slug'         => $slug,
                'term_id'      => $termId,
            ]);
            return $termName;
        }

        // Step 4: Only create if we've confirmed it doesn't exist
        Log::info("Attribute term does not exist, creating new WooCommerce attribute term", [
            'attribute_id' => $attributeId,
            'term_name'    => $termName,
            'slug'         => $slug,
        ]);

        try {
            $term = $this->apiClient->createAttributeTerm($attributeId, $termName);

            $termId = $term['id'];
            $this->termCache[$attributeId][$termName] = $termId;

            Log::info("✓ Created new WooCommerce attribute term", [
                'attribute_id' => $attributeId,
                'term_name'    => $termName,
                'slug'         => $slug,
                'term_id'      => $termId,
            ]);

            return $termName;
        } catch ( \Exception $e ) {
            // Step 5: If creation fails (e.g., slug conflict), check one final time
            Log::warning("Term creation failed, performing final check for existing term", [
                'attribute_id' => $attributeId,
                'term_name'    => $termName,
                'slug'         => $slug,
                'error'        => $e->getMessage(),
            ]);

            $term = $this->apiClient->getAttributeTermBySlug($attributeId, $slug);
            if ( $term ) {
                $termId = $term['id'];
                $this->termCache[$attributeId][$termName] = $termId;
                Log::info("✓ Found existing WooCommerce attribute term after creation failure", [
                    'attribute_id' => $attributeId,
                    'term_name'    => $termName,
                    'slug'         => $slug,
                    'term_id'      => $termId,
                ]);
                return $termName;
            }

            // Re-throw if we still can't find it after all checks
            Log::error("Failed to create or find attribute term after all checks", [
                'attribute_id' => $attributeId,
                'term_name'    => $termName,
                'slug'         => $slug,
                'error'        => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get attribute IDs for Color and Size (for use in variations)
     */
    public function getColorAttributeId () : int {
        return $this->getOrCreateAttribute('Color');
    }

    public function getSizeAttributeId () : int {
        return $this->getOrCreateAttribute('Size');
    }
}


