<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ScrapeJob;
use App\Services\Scrapers\Nike\NikeCategoryScraper;
use App\Services\Scrapers\Nike\NikeScraperOrchestrator;
use Exception;
use Illuminate\Console\Command;

class NikeCollectProductLinks extends Command {
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scrapers:nike:collect-links
                            {--api-url= : Optional full API URL. If not provided, uses default sale category}
                            {--category-concurrency=5 : Number of concurrent API requests}
                            {--max-products= : Maximum number of product URLs to collect (for testing)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Collect product URLs from Nike sale category using their API (Step 1: Link collection only).';

    /**
     * Execute the console command.
     */
    public function handle () : int {
        $apiUrl             = $this->option('api-url');
        $categoryConcurrency = max(1, (int) $this->option('category-concurrency'));
        $maxProducts        = $this->option('max-products') ? max(1, (int) $this->option('max-products')) : null;

        $this->info('Starting Nike product link collection...');
        if ( $apiUrl ) {
            $this->info("API URL: {$apiUrl}");
        } else {
            $this->info("Using default sale category API endpoint");
        }
        $this->info("Category concurrency: {$categoryConcurrency}");
        if ( $maxProducts ) {
            $this->info("Max products limit: {$maxProducts}");
        }

        try {
            $website = NikeScraperOrchestrator::ensureWebsite();
            $this->info("Using website: {$website->name} (ID: {$website->id})");

            $job = ScrapeJob::create([
                'website_id' => $website->id,
                'status'     => 'pending',
            ]);

            $this->info("Created scrape job ID: {$job->id}");

            $categoryScraper = new NikeCategoryScraper();
            $orchestrator    = new NikeScraperOrchestrator($categoryScraper, $website);

            $productUrls = $orchestrator->collectProductLinks($apiUrl, $job, $categoryConcurrency, $maxProducts);

            $job->refresh();

            $this->info('');
            $this->info('Link collection completed successfully!');
            $this->info("Status: {$job->status}");
            $this->info("Total URLs found: {$job->total_found}");

            if ( $job->started_at && $job->finished_at ) {
                $duration = $job->started_at->diffInSeconds($job->finished_at);
                $this->info("Duration: {$duration} seconds");
                if ( $job->total_found > 0 ) {
                    $rate = round($job->total_found / $duration, 2);
                    $this->info("Rate: {$rate} URLs/second");
                }
            }

            $this->info('');
            $this->info('Product URLs have been saved to: storage/logs/nike/product-urls-*.txt');

            return Command::SUCCESS;

        } catch ( Exception $e ) {
            $this->error('Link collection failed: ' . $e->getMessage());
            $this->error($e->getTraceAsString());

            return Command::FAILURE;
        }
    }
}

