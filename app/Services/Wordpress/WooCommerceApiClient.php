<?php

namespace App\Services\Wordpress;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * WooCommerce REST API Client
 *
 * Handles all interactions with the WooCommerce REST API v3,
 * including products, variations, attributes, terms, and categories.
 */
class WooCommerceApiClient {
    protected string $baseUrl;
    protected string $consumerKey;
    protected string $consumerSecret;
    protected string $apiVersion;

    public function __construct () {
        $config               = config('wordpress');
        $this->baseUrl        = rtrim($config['base_url'] ?? '', '/');
        $this->consumerKey    = $config['consumer_key'] ?? '';
        $this->consumerSecret = $config['consumer_secret'] ?? '';
        $this->apiVersion     = $config['api_version'] ?? 'wc/v3';
    }

    /**
     * Create or update a product
     */
    public function upsertProduct (int $wooProductId = null, array $productData) : array {
        $endpoint = '/products';

        if ( $wooProductId ) {
            $response = $this->request('PUT', "{$endpoint}/{$wooProductId}", $productData);
        } else {
            $response = $this->request('POST', $endpoint, $productData);
        }

        if ( !$response->successful() ) {
            throw new RuntimeException("WooCommerce API error: " . $response->body() . " (Status: {$response->status()})");
        }

        return $response->json();
    }

    /**
     * Make an authenticated HTTP request to WooCommerce API
     */
    protected function request (string $method, string $endpoint, array $data = []) : Response {
        $url = $this->getBaseEndpoint() . $endpoint;

        // Disable SSL verification for development/staging environments
        // This is needed when SSL certificates don't match the hostname
        $response = Http::withoutVerifying()
            ->withBasicAuth($this->consumerKey, $this->consumerSecret)
            ->{strtolower($method)}($url, $data);

        if ( !$response->successful() ) {
            Log::error("WooCommerce API request failed", [
                'method'   => $method,
                'endpoint' => $endpoint,
                'status'   => $response->status(),
                'body'     => $response->body(),
            ]);
        }

        return $response;
    }

    /**
     * Get the base API endpoint URL
     */
    protected function getBaseEndpoint () : string {
        return "{$this->baseUrl}/wp-json/{$this->apiVersion}";
    }

    /**
     * Get a product by ID
     */
    public function getProduct (int $wooProductId) : ?array {
        $response = $this->request('GET', "/products/{$wooProductId}");

        if ( $response->successful() ) {
            return $response->json();
        }

        return null;
    }

    /**
     * Get all variations for a product
     */
    public function getProductVariations (int $wooProductId) : array {
        $response = $this->request('GET', "/products/{$wooProductId}/variations");

        if ( $response->successful() ) {
            return $response->json() ?? [];
        }

        return [];
    }

    /**
     * Create a variation for a product
     */
    public function createVariation (int $wooProductId, array $variationData) : array {
        $response = $this->request('POST', "/products/{$wooProductId}/variations", $variationData);

        if ( !$response->successful() ) {
            throw new RuntimeException("Failed to create variation: " . $response->body() . " (Status: {$response->status()})");
        }

        return $response->json();
    }

    /**
     * Update a variation
     */
    public function updateVariation (int $wooProductId, int $variationId, array $variationData) : array {
        $response = $this->request('PUT', "/products/{$wooProductId}/variations/{$variationId}", $variationData);

        if ( !$response->successful() ) {
            throw new RuntimeException("Failed to update variation: " . $response->body() . " (Status: {$response->status()})");
        }

        return $response->json();
    }

    /**
     * Delete a variation
     */
    public function deleteVariation (int $wooProductId, int $variationId) : bool {
        $response = $this->request('DELETE', "/products/{$wooProductId}/variations/{$variationId}");

        return $response->successful();
    }

    /**
     * Get an attribute by name (case-insensitive)
     */
    public function getAttributeByName (string $name) : ?array {
        $attributes = $this->getAttributes();

        foreach ( $attributes as $attribute ) {
            if ( strcasecmp($attribute['name'] ?? '', $name) === 0 ) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * Get an attribute by slug (case-insensitive)
     */
    public function getAttributeBySlug (string $slug) : ?array {
        $attributes = $this->getAttributes();
        $normalizedSlug = strtolower(trim($slug));

        foreach ( $attributes as $attribute ) {
            $attributeSlug = strtolower(trim($attribute['slug'] ?? ''));
            if ( $attributeSlug === $normalizedSlug ) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * Generate slug from attribute name
     */
    protected function generateAttributeSlug (string $name) : string {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($name)));
        return trim($slug, '-');
    }

 
    public function getAttributes () : array {
        $perPage = 100;
        $page    = 1;
        $all     = [];
    
        while (true) {
            $response = $this->request('GET', '/products/attributes', [
                'per_page' => $perPage,
                'page'     => $page,
            ]);
    
            if (!$response->successful()) {
                return $all; // keep whatever we collected
            }
    
            $data = $response->json() ?? [];
            if (empty($data)) {
                break;
            }
    
            $all = array_merge($all, $data);
    
            if (count($data) < $perPage) {
                break; // last page
            }
    
            $page++;
            if ($page > 200) { // safety guard
                break;
            }
        }
    
        return $all;
    }
    


    /**
     * Create a product attribute
     */
    public function createAttribute (string $name, array $options = []) : array {
        $data     = array_merge(['name' => $name], $options);
        $response = $this->request('POST', '/products/attributes', $data);

        if ( !$response->successful() ) {
            throw new RuntimeException("Failed to create attribute '{$name}': " . $response->body() . " (Status: {$response->status()})");
        }

        return $response->json();
    }

    /**
     * Get a term by slug for an attribute (case-insensitive)
     */
    public function getAttributeTermBySlug (int $attributeId, string $slug) : ?array {
        $terms = $this->getAttributeTerms($attributeId);
        $normalizedSlug = strtolower(trim($slug));

        foreach ( $terms as $term ) {
            $termSlug = strtolower(trim($term['slug'] ?? ''));
            if ( $termSlug === $normalizedSlug ) {
                return $term;
            }
        }

        return null;
    }

    /**
     * Get a term by name for an attribute (case-insensitive)
     */
    public function getAttributeTermByName (int $attributeId, string $termName) : ?array {
        $terms = $this->getAttributeTerms($attributeId);

        foreach ( $terms as $term ) {
            if ( strcasecmp($term['name'] ?? '', $termName) === 0 ) {
                return $term;
            }
        }

        return null;
    }

    /**
     * Get all terms for an attribute (with pagination support)
     */
    public function getAttributeTerms (int $attributeId) : array {
        $perPage = 100;
        $page    = 1;
        $all     = [];

        while (true) {
            $response = $this->request('GET', "/products/attributes/{$attributeId}/terms", [
                'per_page' => $perPage,
                'page'     => $page,
            ]);

            if (!$response->successful()) {
                return $all; // keep whatever we collected
            }

            $data = $response->json() ?? [];
            if (empty($data)) {
                break;
            }

            $all = array_merge($all, $data);

            if (count($data) < $perPage) {
                break; // last page
            }

            $page++;
            if ($page > 200) { // safety guard
                break;
            }
        }

        return $all;
    }

    /**
     * Create a term for an attribute
     */
    public function createAttributeTerm (int $attributeId, string $termName) : array {
        $response = $this->request('POST', "/products/attributes/{$attributeId}/terms", [
            'name' => $termName,
        ]);

        if ( !$response->successful() ) {
            throw new RuntimeException("Failed to create term '{$termName}' for attribute {$attributeId}: " . $response->body() . " (Status: {$response->status()})");
        }

        return $response->json();
    }

    /**
     * Get a category by name (case-insensitive)
     */
    public function getCategoryByName (string $name) : ?array {
        $categories = $this->getCategories();

        foreach ( $categories as $category ) {
            if ( strcasecmp($category['name'] ?? '', $name) === 0 ) {
                return $category;
            }
        }

        return null;
    }

    /**
     * Get all product categories
     */
    public function getCategories () : array {
        $response = $this->request('GET', '/products/categories');

        if ( $response->successful() ) {
            return $response->json() ?? [];
        }

        return [];
    }

    /**
     * Create a product category
     */
    public function createCategory (string $name, int $parentId = null) : array {
        $data = ['name' => $name];
        if ( $parentId !== null ) {
            $data['parent'] = $parentId;
        }

        $response = $this->request('POST', '/products/categories', $data);

        if ( !$response->successful() ) {
            throw new RuntimeException("Failed to create category '{$name}': " . $response->body() . " (Status: {$response->status()})");
        }

        return $response->json();
    }

    /**
     * Upload an image to WordPress Media Library from local file path
     * Returns the uploaded media object with id and source_url, or null on failure
     *
     * @param string $localPath Absolute path to the image file
     * @param string $altText Optional alt text for the image
     * @return array|null Media object with 'id' and 'source_url', or null on failure
     */
    public function uploadMediaFromLocalPath (string $localPath, string $altText = '') : ?array {
        try {
            // Verify file exists and is readable
            if ( !file_exists($localPath) || !is_readable($localPath) ) {
                Log::warning("Image file not found or not readable", [
                    'local_path' => $localPath,
                ]);
                return null;
            }

            // Read file binary data
            $imageData = file_get_contents($localPath);
            if ( $imageData === false ) {
                Log::error("Failed to read image file", ['local_path' => $localPath]);
                return null;
            }

            // Detect MIME type using finfo_file (more reliable than mime_content_type)
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $localPath);
            finfo_close($finfo);

            // Fallback to mime_content_type if finfo fails
            if ( !$mimeType || $mimeType === 'application/octet-stream' ) {
                $mimeType = mime_content_type($localPath) ?: 'image/jpeg';
            }

            $filename = basename($localPath);

            // Upload to WordPress Media Library using WordPress REST API
            // The endpoint is /wp/v2/media (not /wc/v3)
            $baseEndpoint = $this->getBaseEndpoint();
            $mediaEndpoint = str_replace('/wc/v3', '/wp/v2', $baseEndpoint) . '/media';
            $url = $mediaEndpoint;

            Log::info("Uploading image to WordPress Media Library", [
                'local_path' => $localPath,
                'filename' => $filename,
                'mime_type' => $mimeType,
                'endpoint' => $url,
            ]);

            // WordPress Media API requires WordPress Application Password authentication
            // Check if separate WordPress credentials are configured
            $config = config('wordpress');
            $wpUsername = $config['wp_username'] ?? null;
            $wpAppPassword = $config['wp_app_password'] ?? null;

            // Build HTTP client
            $httpClient = Http::withoutVerifying();

            // Use WordPress Application Password if available, otherwise try WooCommerce keys
            if ( $wpUsername && $wpAppPassword ) {
                // WordPress Application Password format: username:application_password
                $httpClient = $httpClient->withBasicAuth($wpUsername, $wpAppPassword);
                Log::debug("Using WordPress Application Password for media upload");
            } else {
                // Fallback: Try WooCommerce keys (may not work for /wp/v2 endpoints)
                $httpClient = $httpClient->withBasicAuth($this->consumerKey, $this->consumerSecret);
                Log::debug("Using WooCommerce API keys for media upload (may not work)");
            }

            // Use multipart/form-data to upload the file
            $response = $httpClient
                ->attach('file', $imageData, $filename, [
                    'Content-Type' => $mimeType,
                ])
                ->post($url, [
                    'title' => pathinfo($filename, PATHINFO_FILENAME),
                    'alt_text' => $altText,
                ]);

            if ( !$response->successful() ) {
                Log::error("Failed to upload image to WordPress Media Library", [
                    'local_path' => $localPath,
                    'filename' => $filename,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $uploadedImage = $response->json();
            
            // WordPress Media API returns 'id' and 'source_url'
            $mediaId = $uploadedImage['id'] ?? null;
            $imageUrl = $uploadedImage['source_url'] ?? $uploadedImage['url'] ?? null;
            
            if ( !$mediaId ) {
                Log::error("Uploaded image but no media ID returned", [
                    'local_path' => $localPath,
                    'response' => $uploadedImage,
                ]);
                return null;
            }
            
            Log::info("Image uploaded successfully to WordPress Media Library", [
                'local_path' => $localPath,
                'filename' => $filename,
                'media_id' => $mediaId,
                'source_url' => $imageUrl,
            ]);

            return [
                'id' => $mediaId,
                'source_url' => $imageUrl,
                'alt_text' => $uploadedImage['alt_text'] ?? $altText,
            ];
        } catch ( \Exception $e ) {
            Log::error("Exception while uploading image to WordPress Media Library", [
                'local_path' => $localPath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Upload an image to WooCommerce from local file
     * Returns the uploaded image data with src URL
     * 
     * @deprecated Use uploadMediaFromLocalPath() instead
     */
    public function uploadImage (string $localPath, string $altText = '') : ?array {
        $result = $this->uploadMediaFromLocalPath($localPath, $altText);
        if ( !$result ) {
            return null;
        }
        return [
            'id' => $result['id'],
            'src' => $result['source_url'],
            'alt' => $result['alt_text'],
        ];
    }
}


