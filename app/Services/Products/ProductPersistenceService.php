<?php

namespace App\Services\Products;

use App\Models\Product;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductPersistenceService {
    protected MediaDownloader $mediaDownloader;

    public function __construct (MediaDownloader $mediaDownloader) {
        $this->mediaDownloader = $mediaDownloader;
    }

    public function storeOrUpdate (Website $website, array $normalizedProduct) : Product {
        return DB::transaction(function () use ($website, $normalizedProduct) {
            $product = Product::firstOrNew([
                'website_id'  => $website->id,
                'external_id' => $normalizedProduct['external_id'],
            ]);

            $product->fill([
                'title'          => $normalizedProduct['title'] ?? '',
                'slug'           => $normalizedProduct['slug'] ?? '',
                'description'    => $normalizedProduct['description'] ?? null,
                'price'          => $normalizedProduct['price'] ?? null,
                'currency'       => $normalizedProduct['currency'] ?? null,
                'stock_quantity' => $normalizedProduct['stock_quantity'] ?? null,
                'status'         => $normalizedProduct['status'] ?? 'draft',
                'raw_data'       => $normalizedProduct['raw'] ?? $normalizedProduct['raw_data'] ?? null,
            ]);

            $product->save();

            $this->syncMedia($product, $website, $normalizedProduct['media'] ?? []);
            $this->syncAttributes($product, $normalizedProduct['attributes'] ?? []);

            return $product;
        });
    }

    protected function syncMedia (Product $product, Website $website, array $mediaItems) : void {
        $product->media()->delete();

        foreach ( $mediaItems as $index => $mediaItem ) {
            $sourceUrl = $mediaItem['source_url'] ?? '';

            if ( empty($sourceUrl) ) {
                continue;
            }

            $localPath = $this->mediaDownloader->download($sourceUrl, $website, $product, $mediaItem['type'] ?? 'image');

            if ( !$localPath ) {
                Log::warning("Failed to download media", [
                    'product_id' => $product->id,
                    'url'        => $sourceUrl,
                ]);
                continue;
            }

            $product->media()->create([
                'type'       => $mediaItem['type'] ?? 'image',
                'source_url' => $sourceUrl,
                'local_path' => $localPath,
                'alt_text'   => $mediaItem['alt_text'] ?? $mediaItem['alt'] ?? null,
                'is_primary' => $index === 0 || ($mediaItem['is_primary'] ?? false),
            ]);
        }
    }

    protected function syncAttributes (Product $product, array $attributes) : void {
        $product->attributes()->delete();

        foreach ( $attributes as $key => $attribute ) {
            if ( is_array($attribute) && isset($attribute['name']) ) {
                $product->attributes()->create([
                    'name'  => $attribute['name'],
                    'value' => $attribute['value'] ?? null,
                ]);
            } elseif ( is_string($key) ) {
                $product->attributes()->create([
                    'name'  => $key,
                    'value' => is_string($attribute) || is_numeric($attribute) ? $attribute : null,
                ]);
            } elseif ( is_string($attribute) ) {
                $product->attributes()->create([
                    'name'  => $attribute,
                    'value' => null,
                ]);
            }
        }
    }
}

