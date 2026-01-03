<?php

declare(strict_types=1);

namespace App\Services\Scrapers\Nike;

use App\Models\ScrapeJob;
use App\Models\Website;
use Exception;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class NikeScraperOrchestrator {
    private NikeCategoryScraper $categoryScraper;
    private Website             $website;

    public function __construct (
        NikeCategoryScraper $categoryScraper, Website $website
    ) {
        $this->categoryScraper = $categoryScraper;
        $this->website         = $website;
    }

    /**
     * Ensure the Nike website exists in the database.
     */
    public static function ensureWebsite () : Website {
        $website = Website::where('slug', 'nike')->first();

        if ( !$website ) {
            $trashedWebsite = Website::withTrashed()->where('slug', 'nike')->first();

            if ( $trashedWebsite ) {
                $trashedWebsite->restore();
                $website = $trashedWebsite;
            } else {
                $website = Website::create([
                    'name'          => 'Nike',
                    'base_url'      => 'https://www.nike.com',
                    'slug'          => 'nike',
                    'is_active'     => true,
                    'scraper_class' => self::class,
                    'extra_config'  => [],
                ]);
            }
        }

        $website->update([
            'name'          => 'Nike',
            'base_url'      => 'https://www.nike.com',
            'is_active'     => true,
            'scraper_class' => self::class,
        ]);

        $website->refresh();

        return $website;
    }

    /**
     * Collect product URLs from Nike sale category (Step 1 only - link collection).
     *
     * @param string|null    $apiUrl Optional full API URL. If not provided, uses default sale category
     * @param ScrapeJob|null $job Optional scrape job for tracking
     * @param int            $categoryConcurrency Number of concurrent API requests
     * @param int|null       $maxProducts Maximum number of product URLs to collect (null = no limit)
     *
     * @return array Array of product URLs
     */
    public function collectProductLinks (
        ?string $apiUrl = null, ?ScrapeJob $job = null, int $categoryConcurrency = 5, ?int $maxProducts = null
    ) : array {
        $overallStartTime = microtime(true);

        try {
            if ( $job ) {
                $job->update([
                    'status'     => 'running',
                    'started_at' => now(),
                ]);
            }

            Log::info('Starting Nike category link collection', [
                'api_url'              => $apiUrl ?? 'default',
                'job_id'               => $job?->id,
                'category_concurrency' => $categoryConcurrency,
                'max_products'         => $maxProducts,
            ]);

            $categoryStartTime = microtime(true);
            Log::info('Step 1: Collecting product URLs from Nike API', [
                'category_concurrency' => $categoryConcurrency,
            ]);

            $productUrls = $this->categoryScraper->collectProductLinks($apiUrl, $categoryConcurrency);

            $categoryDuration = microtime(true) - $categoryStartTime;
            $totalUrls        = count($productUrls);

            Log::info('Product URLs collected', [
                'total_urls' => $totalUrls,
                'duration'   => round($categoryDuration, 2) . 's',
            ]);

            if ( empty($productUrls) ) {
                throw new RuntimeException('No product URLs found from Nike API');
            }

            if ( $maxProducts !== null && $maxProducts > 0 && $totalUrls > $maxProducts ) {
                $productUrls = array_slice($productUrls, 0, $maxProducts);
                $totalUrls   = count($productUrls);
                Log::info('Limited product URLs to max_products', [
                    'max_products' => $maxProducts,
                    'total_urls'   => $totalUrls,
                ]);
            }

            // Save product URLs to log file
            $this->saveProductUrlsToLog($productUrls);

            $overallDuration = microtime(true) - $overallStartTime;

            if ( $job ) {
                $job->update([
                    'status'      => 'completed',
                    'finished_at' => now(),
                    'total_found' => $totalUrls,
                ]);
            }

            Log::info('Nike category link collection completed successfully', [
                'api_url'            => $apiUrl ?? 'default',
                'total_products'     => $totalUrls,
                'collection_duration' => round($categoryDuration, 2) . 's',
                'total_duration'     => round($overallDuration, 2) . 's',
                'overall_rate'       => $totalUrls > 0 ? round($totalUrls / $overallDuration, 2) . ' URLs/s' : '0 URLs/s',
            ]);

            return $productUrls;

        } catch ( Exception $e ) {
            $errorMessage = $e->getMessage();
            if ( strlen($errorMessage) > 1000 ) {
                $errorMessage = substr($errorMessage, 0, 1000) . '...';
            }

            if ( $job ) {
                $job->update([
                    'status'        => 'failed',
                    'finished_at'   => now(),
                    'error_message' => $errorMessage,
                ]);
            }

            Log::error('Nike category link collection failed', [
                'api_url' => $apiUrl ?? 'default',
                'error'   => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Save product URLs to a log file.
     */
    private function saveProductUrlsToLog (array $productUrls) : void {
        try {
            $logDir = storage_path('logs/nike');
            if ( !file_exists($logDir) ) {
                mkdir($logDir, 0755, true);
            }

            $timestamp = now()->format('Y-m-d_H-i-s');
            $filename  = "product-urls-{$timestamp}.txt";
            $filePath  = $logDir . '/' . $filename;

            $content = implode("\n", $productUrls) . "\n";
            file_put_contents($filePath, $content);

            Log::info('Product URLs saved to log file', [
                'file_path'    => $filePath,
                'file_size'    => filesize($filePath),
                'total_urls'   => count($productUrls),
            ]);
        } catch ( Exception $e ) {
            Log::warning('Failed to save product URLs to log file', [
                'error'      => $e->getMessage(),
                'total_urls' => count($productUrls),
            ]);
        }
    }
}

