<?php

namespace App\Services\Products;

use App\Models\Product;
use App\Models\Website;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaDownloader {
    public function download (string $url, Website $website, Product $product, string $type) : ?string {
        try {
            $response = Http::timeout(30)->get($url);

            if ( !$response->successful() ) {
                return null;
            }

            $content   = $response->body();
            $extension = $this->guessExtension($url, $response->header('Content-Type'));

            $filename  = $this->generateFilename($product->title, $extension);
            $directory = "products/{$website->slug}/{$product->id}";
            $path      = "{$directory}/{$filename}";

            Storage::disk('public')->put($path, $content);

            return $path;
        } catch ( Exception $e ) {
            return null;
        }
    }

    protected function guessExtension (string $url, ?string $contentType) : string {
        $urlExtension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);

        if ( $urlExtension && in_array(strtolower($urlExtension), [
                'jpg',
                'jpeg',
                'png',
                'gif',
                'webp',
                'mp4',
                'webm',
                'mov'
            ]) ) {
            return strtolower($urlExtension);
        }

        if ( $contentType ) {
            $mimeMap = [
                'image/jpeg'      => 'jpg',
                'image/png'       => 'png',
                'image/gif'       => 'gif',
                'image/webp'      => 'webp',
                'video/mp4'       => 'mp4',
                'video/webm'      => 'webm',
                'video/quicktime' => 'mov',
            ];

            if ( isset($mimeMap[$contentType]) ) {
                return $mimeMap[$contentType];
            }
        }

        return 'jpg';
    }

    protected function generateFilename (string $title, string $extension) : string {
        $slug   = Str::slug($title);
        $random = Str::random(8);

        return "{$slug}-{$random}.{$extension}";
    }
}

