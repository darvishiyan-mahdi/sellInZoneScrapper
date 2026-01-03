<?php

namespace App\Services\Scrapers;

use Illuminate\Support\Str;

class ExampleShopScraper extends BaseScraper {
    public function fetchProducts () : iterable {
        // TODO: Implement actual HTTP request to fetch products
        // Example: $response = Http::get($this->website->base_url . '/api/products');
        // For now, return a mock array structure

        $mockProducts = [
            [
                'id'          => '12345',
                'title'       => 'Sample Product 1',
                'description' => 'This is a sample product description',
                'price'       => 29.99,
                'currency'    => 'USD',
                'stock'       => 10,
                'images'      => [
                    ['url' => 'https://example.com/image1.jpg', 'alt' => 'Product image 1'],
                    ['url' => 'https://example.com/image2.jpg', 'alt' => 'Product image 2'],
                ],
                'attributes'  => [
                    'color' => 'Blue',
                    'size'  => 'Large',
                    'brand' => 'ExampleBrand',
                ],
            ],
            [
                'id'          => '12346',
                'title'       => 'Sample Product 2',
                'description' => 'Another sample product',
                'price'       => 49.99,
                'currency'    => 'USD',
                'stock'       => 5,
                'images'      => [
                    ['url' => 'https://example.com/image3.jpg', 'alt' => 'Product image 3'],
                ],
                'attributes'  => [
                    'color' => 'Red',
                    'size'  => 'Medium',
                ],
            ],
        ];

        foreach ( $mockProducts as $product ) {
            yield $product;
        }
    }

    public function normalizeProduct (array $rawItem) : array {
        return [
            'website_id'     => $this->website->id,
            'external_id'    => $rawItem['id'] ?? null,
            'title'          => $rawItem['title'] ?? '',
            'slug'           => Str::slug($rawItem['title'] ?? 'product-' . ($rawItem['id'] ?? uniqid())),
            'description'    => $rawItem['description'] ?? null,
            'price'          => $rawItem['price'] ?? null,
            'currency'       => $rawItem['currency'] ?? 'USD',
            'stock_quantity' => $rawItem['stock'] ?? null,
            'status'         => 'active',
            'raw'            => $rawItem,
            'media'          => array_map(function ($media) {
                return [
                    'type'       => 'image',
                    'source_url' => $media['url'] ?? '',
                    'local_path' => '', // Will be set during download
                    'alt_text'   => $media['alt'] ?? null,
                    'is_primary' => false,
                ];
            }, $rawItem['images'] ?? []),
            'attributes'     => array_map(function ($name, $value) {
                return [
                    'name'  => $name,
                    'value' => $value,
                ];
            }, array_keys($rawItem['attributes'] ?? []), $rawItem['attributes'] ?? []),
        ];
    }
}

