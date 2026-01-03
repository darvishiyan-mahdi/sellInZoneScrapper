<?php

namespace App\Services\Scrapers;

use App\Models\ScrapeJob;
use App\Models\Website;
use App\Services\Products\ProductPersistenceService;
use App\Services\Scrapers\Contracts\ScraperInterface;
use Exception;
use Illuminate\Support\Facades\Log;

abstract class BaseScraper implements ScraperInterface {
    protected Website                   $website;
    protected ?ScrapeJob                $job = null;
    protected ProductPersistenceService $persistenceService;

    public function __construct (Website $website, ProductPersistenceService $persistenceService) {
        $this->website            = $website;
        $this->persistenceService = $persistenceService;
    }

    public function run (ScrapeJob $job) : void {
        $this->job = $job;

        try {
            $job->update([
                'status'     => 'running',
                'started_at' => now(),
            ]);

            Log::info("Starting scrape for website: {$this->website->name}", [
                'website_id' => $this->website->id,
                'job_id'     => $job->id,
            ]);

            $totalFound   = 0;
            $totalCreated = 0;
            $totalUpdated = 0;

            foreach ( $this->fetchProducts() as $rawItem ) {
                $totalFound++;
                $normalized = $this->normalizeProduct($rawItem);

                $product = $this->persistenceService->storeOrUpdate($this->website, $normalized);

                if ( $product->wasRecentlyCreated ) {
                    $totalCreated++;
                } else {
                    $totalUpdated++;
                }
            }

            $job->update([
                'status'        => 'success',
                'finished_at'   => now(),
                'total_found'   => $totalFound,
                'total_created' => $totalCreated,
                'total_updated' => $totalUpdated,
            ]);

            Log::info("Scrape completed successfully", [
                'website_id'    => $this->website->id,
                'job_id'        => $job->id,
                'total_found'   => $totalFound,
                'total_created' => $totalCreated,
                'total_updated' => $totalUpdated,
            ]);
        } catch ( Exception $e ) {
            $job->update([
                'status'        => 'failed',
                'finished_at'   => now(),
                'error_message' => $e->getMessage(),
            ]);

            Log::error("Scrape failed", [
                'website_id' => $this->website->id,
                'job_id'     => $job->id,
                'error'      => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    abstract public function fetchProducts () : iterable;

    abstract public function normalizeProduct (array $rawItem) : array;
}

