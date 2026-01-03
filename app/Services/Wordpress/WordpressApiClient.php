<?php

namespace App\Services\Wordpress;

use App\Models\Product;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WordpressApiClient {
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

    public function upsertProduct (Product $product) : array {
        $mapping  = $product->wordpressMapping;
        $endpoint = $this->getEndpoint();

        $payload = $this->buildPayload($product);

        if ( $mapping && $mapping->wordpress_product_id ) {
            $response = Http::withBasicAuth($this->consumerKey, $this->consumerSecret)
                            ->put("{$endpoint}/{$mapping->wordpress_product_id}", $payload);
        } else {
            $response = Http::withBasicAuth($this->consumerKey, $this->consumerSecret)->post($endpoint, $payload);
        }

        if ( !$response->successful() ) {
            throw new RuntimeException("WordPress API error: " . $response->body());
        }

        return $response->json();
    }

    protected function getEndpoint () : string {
        return "{$this->baseUrl}/wp-json/{$this->apiVersion}/products";
    }

    protected function buildPayload (Product $product) : array {
        $payload = [
            'name'           => $product->title,
            'description'    => $product->description ?? '',
            'regular_price'  => $product->price ? (string) $product->price : '',
            'stock_quantity' => $product->stock_quantity ?? 0,
            'manage_stock'   => $product->stock_quantity !== null,
            'status'         => $this->mapStatus($product->status),
        ];

        $images = [];
        foreach ( $product->media as $media ) {
            $imageUrl = $media->local_path ? asset("storage/{$media->local_path}") : $media->source_url;

            $images[] = [
                'src' => $imageUrl,
                'alt' => $media->alt_text ?? '',
            ];
        }
        $payload['images'] = $images;

        $metaData = [];
        foreach ( $product->attributes as $attribute ) {
            $metaData[] = [
                'key'   => $attribute->name,
                'value' => $attribute->value,
            ];
        }
        $payload['meta_data'] = $metaData;

        return $payload;
    }

    protected function mapStatus (string $status) : string {
        $statusMap = [
            'active'   => 'publish',
            'draft'    => 'draft',
            'archived' => 'private',
        ];

        return $statusMap[$status] ?? 'draft';
    }
}

