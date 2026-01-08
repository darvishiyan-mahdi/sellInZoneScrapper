<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ScrapeJob;
use App\Services\Scrapers\Nike\NikeCategoryScraper;
use App\Services\Scrapers\Nike\NikeProductDetailScraper;
use App\Services\Scrapers\Nike\NikeScraperOrchestrator;
use Exception;
use Illuminate\Console\Command;

class NikeScrapeCategory extends Command {
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scrapers:nike:scrape-category
                            {--api-url= : Optional full API URL. If not provided, uses default sale category endpoint}
                            {--category-concurrency=5 : Number of concurrent category page requests}
                            {--pdp-concurrency=20 : Number of concurrent product detail page requests}
                            {--batch-size=200 : Number of product URLs to process per batch}
                            {--max-products= : Maximum number of products to scrape (for testing)}
                            {--batch-sleep=0.2 : Sleep time in seconds between batches}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scrape a Nike category: collect product URLs and scrape product details.';

    /**
     * Execute the console command.
     */
    public function handle () : int {
        $apiUrl             = $this->option('api-url');
        $categoryConcurrency = max(1, (int) $this->option('category-concurrency'));
        $pdpConcurrency      = max(1, (int) $this->option('pdp-concurrency'));
        $batchSize           = max(10, (int) $this->option('batch-size'));
        $maxProducts         = $this->option('max-products') ? max(1, (int) $this->option('max-products')) : null;
        $batchSleep          = max(0.0, (float) $this->option('batch-sleep'));

        $this->info('Starting Nike category scraper...');
        if ( $apiUrl ) {
            $this->info("API URL: {$apiUrl}");
        } else {
            $this->info("Using default sale category API endpoint");
        }
        $this->info("Category page concurrency: {$categoryConcurrency}");
        $this->info("Product detail concurrency: {$pdpConcurrency}");
        $this->info("Product batch size: {$batchSize}");
        if ( $maxProducts ) {
            $this->info("Max products limit: {$maxProducts}");
        }
        $this->info("Batch sleep: {$batchSleep}s");

        try {
            $website = NikeScraperOrchestrator::ensureWebsite();
            $this->info("Using website: {$website->name} (ID: {$website->id})");

            $job = ScrapeJob::create([
                'website_id' => $website->id,
                'status'     => 'pending',
            ]);

            $this->info("Created scrape job ID: {$job->id}");

            $categoryScraper      = new NikeCategoryScraper();
            $productDetailScraper = new NikeProductDetailScraper($website);
            $orchestrator         = new NikeScraperOrchestrator($categoryScraper, $productDetailScraper, $website);

            $orchestrator->scrapeCategory($apiUrl, $job, $categoryConcurrency, $pdpConcurrency, $batchSize, $maxProducts, $batchSleep);

            $job->refresh();

            $this->info('');
            $this->info('Scrape completed successfully!');
            $this->info("Status: {$job->status}");
            $this->info("Total found: {$job->total_found}");

            if ( $job->started_at && $job->finished_at ) {
                $duration = $job->started_at->diffInSeconds($job->finished_at);
                $this->info("Duration: {$duration} seconds");
                if ( $job->total_found > 0 ) {
                    $rate = round($job->total_found / $duration, 2);
                    $this->info("Rate: {$rate} products/second");
                }
            }

            return Command::SUCCESS;

        } catch ( Exception $e ) {
            $this->error('Scrape failed: ' . $e->getMessage());
            $this->error($e->getTraceAsString());

            return Command::FAILURE;
        }
    }
}

