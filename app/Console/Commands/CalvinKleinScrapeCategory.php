<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ScrapeJob;
use App\Services\Scrapers\CalvinKlein\CalvinKleinCategoryScraper;
use App\Services\Scrapers\CalvinKlein\CalvinKleinProductDetailScraper;
use App\Services\Scrapers\CalvinKlein\CalvinKleinScraperOrchestrator;
use Exception;
use Illuminate\Console\Command;

class CalvinKleinScrapeCategory extends Command {
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scrapers:calvinklein:scrape-category
                            {categoryUrl : The category URL to scrape}
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
    protected $description = 'Scrape a Calvin Klein category: collect product URLs and scrape product details.';

    /**
     * Execute the console command.
     */
    public function handle () : int {
        $categoryUrl         = $this->argument('categoryUrl');
        $categoryConcurrency = max(1, (int) $this->option('category-concurrency'));
        $pdpConcurrency      = max(1, (int) $this->option('pdp-concurrency'));
        $batchSize           = max(10, (int) $this->option('batch-size'));
        $maxProducts         = $this->option('max-products') ? max(1, (int) $this->option('max-products')) : null;
        $batchSleep          = max(0.0, (float) $this->option('batch-sleep'));

        if ( empty($categoryUrl) ) {
            $this->error('Category URL is required.');

            return Command::FAILURE;
        }

        $this->info('Starting Calvin Klein category scraper...');
        $this->info("Category URL: {$categoryUrl}");
        $this->info("Category page concurrency: {$categoryConcurrency}");
        $this->info("Product detail concurrency: {$pdpConcurrency}");
        $this->info("Product batch size: {$batchSize}");
        if ( $maxProducts ) {
            $this->info("Max products limit: {$maxProducts}");
        }
        $this->info("Batch sleep: {$batchSleep}s");

        try {
            $website = CalvinKleinScraperOrchestrator::ensureWebsite();
            $this->info("Using website: {$website->name} (ID: {$website->id})");

            $job = ScrapeJob::create([
                'website_id' => $website->id,
                'status'     => 'pending',
            ]);

            $this->info("Created scrape job ID: {$job->id}");

            $categoryScraper      = new CalvinKleinCategoryScraper();
            $productDetailScraper = new CalvinKleinProductDetailScraper($website);
            $orchestrator         = new CalvinKleinScraperOrchestrator($categoryScraper, $productDetailScraper, $website);

            $orchestrator->scrapeCategory($categoryUrl, $job, $categoryConcurrency, $pdpConcurrency, $batchSize, $maxProducts, $batchSleep);

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
