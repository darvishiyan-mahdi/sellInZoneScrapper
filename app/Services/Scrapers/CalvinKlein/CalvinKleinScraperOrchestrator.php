<?php

declare(strict_types=1);

namespace App\Services\Scrapers\CalvinKlein;

use App\Models\ScrapeJob;
use App\Models\Website;
use Exception;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CalvinKleinScraperOrchestrator {
    private CalvinKleinCategoryScraper      $categoryScraper;
    private CalvinKleinProductDetailScraper $productDetailScraper;
    private Website                         $website;

    public function __construct (
        CalvinKleinCategoryScraper $categoryScraper, CalvinKleinProductDetailScraper $productDetailScraper, Website $website
    ) {
        $this->categoryScraper      = $categoryScraper;
        $this->productDetailScraper = $productDetailScraper;
        $this->website              = $website;
    }

    /**
     * Ensure the Calvin Klein website exists in the database.
     */
    public static function ensureWebsite () : Website {
        $website = Website::where('slug', 'calvin-klein')->first();

        if ( !$website ) {
            $trashedWebsite = Website::withTrashed()->where('slug', 'calvin-klein')->first();

            if ( $trashedWebsite ) {
                $trashedWebsite->restore();
                $website = $trashedWebsite;
            } else {
                $website = Website::create([
                    'name'          => 'Calvin Klein',
                    'base_url'      => 'https://www.calvinklein.nl',
                    'slug'          => 'calvin-klein',
                    'is_active'     => true,
                    'scraper_class' => self::class,
                    'extra_config'  => [],
                ]);
            }
        }

        $website->update([
            'name'          => 'Calvin Klein',
            'base_url'      => 'https://www.calvinklein.nl',
            'is_active'     => true,
            'scraper_class' => self::class,
        ]);

        $website->refresh();

        return $website;
    }

    /**
     * Scrape a complete category: collect links and then scrape product details.
     *
     * @param string         $categoryUrl         Category URL
     * @param ScrapeJob|null $job                 Optional scrape job for tracking
     * @param int            $categoryConcurrency Number of concurrent category page requests
     * @param int            $pdpConcurrency      Number of concurrent product detail page requests
     * @param int            $batchSize           Number of product URLs to process per batch
     * @param int|null       $maxProducts         Maximum number of products to scrape (null = no limit)
     * @param float          $batchSleep          Sleep time in seconds between batches
     *
     * @return void
     */
    public function scrapeCategory (
        string $categoryUrl, ?ScrapeJob $job = null, int $categoryConcurrency = 5, int $pdpConcurrency = 20, int $batchSize = 200, ?int $maxProducts = null, float $batchSleep = 0.2
    ) : void {
        $overallStartTime = microtime(true);

        try {
            if ( $job ) {
                $job->update([
                    'status'     => 'running',
                    'started_at' => now(),
                ]);
            }

            Log::info('Starting Calvin Klein category scrape', [
                'category_url'         => $categoryUrl,
                'job_id'               => $job?->id,
                'category_concurrency' => $categoryConcurrency,
                'pdp_concurrency'      => $pdpConcurrency,
                'batch_size'           => $batchSize,
                'max_products'         => $maxProducts,
            ]);

            $categoryStartTime = microtime(true);
            Log::info('Step 1: Collecting product URLs from category pages', [
                'category_concurrency' => $categoryConcurrency,
            ]);

            $productUrls = $this->categoryScraper->collectProductLinks($categoryUrl, $categoryConcurrency);

            $categoryDuration = microtime(true) - $categoryStartTime;
            $totalUrls        = count($productUrls);

            Log::info('Product URLs collected', [
                'total_urls' => $totalUrls,
                'duration'   => round($categoryDuration, 2) . 's',
            ]);

            if ( empty($productUrls) ) {
                throw new RuntimeException('No product URLs found in category');
            }

            if ( $maxProducts !== null && $maxProducts > 0 && $totalUrls > $maxProducts ) {
                $productUrls = array_slice($productUrls, 0, $maxProducts);
                $totalUrls   = count($productUrls);
                Log::info('Limited product URLs to max_products', [
                    'max_products' => $maxProducts,
                    'total_urls'   => $totalUrls,
                ]);
            }

            $pdpStartTime = microtime(true);
            Log::info('Step 2: Scraping product details', [
                'total_products'  => $totalUrls,
                'pdp_concurrency' => $pdpConcurrency,
                'batch_size'      => $batchSize,
                'batch_sleep'     => $batchSleep,
            ]);

            $this->productDetailScraper->scrapeProducts($productUrls, $pdpConcurrency, $batchSize, $maxProducts, $batchSleep);

            $pdpDuration     = microtime(true) - $pdpStartTime;
            $overallDuration = microtime(true) - $overallStartTime;

            if ( $job ) {
                $job->update([
                    'status'      => 'completed',
                    'finished_at' => now(),
                    'total_found' => $totalUrls,
                ]);
            }

            Log::info('Calvin Klein category scrape completed successfully', [
                'category_url'                 => $categoryUrl,
                'total_products'               => $totalUrls,
                'category_collection_duration' => round($categoryDuration, 2) . 's',
                'pdp_scraping_duration'        => round($pdpDuration, 2) . 's',
                'total_duration'               => round($overallDuration, 2) . 's',
                'overall_rate'                 => $totalUrls > 0 ? round($totalUrls / $overallDuration, 2) . ' products/s' : '0 products/s',
            ]);

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

            Log::error('Calvin Klein category scrape failed', [
                'category_url' => $categoryUrl,
                'error'        => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
