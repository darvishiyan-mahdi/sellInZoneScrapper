<?php

namespace App\Console\Commands;

use App\Models\ProductWordpressMapping;
use App\Services\Wordpress\WooCommerceApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateWordpressDescriptionsCommand extends Command
{
    protected $signature = 'wordpress:update-descriptions 
                            {--limit= : Limit number of products to update}
                            {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Update WooCommerce product descriptions with meta description_translated';

    protected WooCommerceApiClient $apiClient;

    public function __construct(WooCommerceApiClient $apiClient)
    {
        parent::__construct();
        $this->apiClient = $apiClient;
    }

    public function handle(): int
    {
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be made');
        }

        $this->info('Fetching products with WordPress mappings...');

        // Get all mappings that have a WordPress product ID
        $query = ProductWordpressMapping::whereNotNull('wordpress_product_id')
            ->whereHas('product', function ($q) {
                $q->whereNotNull('meta');
            })
            ->with('product');

        if ($limit) {
            $query->limit($limit);
        }

        $mappings = $query->get();
        $total = $mappings->count();

        if ($total === 0) {
            $this->warn('No products found with WordPress mappings and meta data.');
            return Command::SUCCESS;
        }

        $this->info("Found {$total} products to process.");
        $this->newLine();

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $bar->setMessage('Starting updates...');
        $bar->start();

        $updated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($mappings as $mapping) {
            $product = $mapping->product;
            $wooProductId = $mapping->wordpress_product_id;

            if (!$product) {
                $skipped++;
                $bar->setMessage("Skipped: Product not found (ID: {$mapping->product_id})");
                $bar->advance();
                continue;
            }

            // Check if meta has description_translated
            $meta = $product->meta ?? [];
            $descriptionTranslated = $meta['description_translated'] ?? null;

            if (empty($descriptionTranslated)) {
                $skipped++;
                $bar->setMessage("Skipped: No description_translated (Product ID: {$product->id})");
                $bar->advance();
                continue;
            }

            try {
                if ($dryRun) {
                    $this->newLine();
                    $this->line("Would update Product #{$wooProductId} (Local ID: {$product->id})");
                    $this->line("  Title: {$product->title}");
                    $this->line("  Description length: " . strlen($descriptionTranslated) . " characters");
                    $updated++;
                } else {
                    // Update the product description via WooCommerce API
                    $productData = [
                        'description' => $descriptionTranslated,
                    ];

                    $this->apiClient->upsertProduct($wooProductId, $productData);

                    $updated++;
                    $bar->setMessage("Updated: {$product->title}");
                }
            } catch (\Exception $e) {
                $failed++;
                $bar->setMessage("Failed: {$product->title} - " . substr($e->getMessage(), 0, 50));
                
                Log::error("Failed to update WooCommerce product description", [
                    'product_id' => $product->id,
                    'woo_product_id' => $wooProductId,
                    'error' => $e->getMessage(),
                ]);

                if ($this->option('verbose')) {
                    $this->newLine();
                    $this->error("Error updating product {$product->id}: {$e->getMessage()}");
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        
        $this->info("Update completed!");
        $this->table(
            ['Status', 'Count'],
            [
                ['Updated', $updated],
                ['Skipped', $skipped],
                ['Failed', $failed],
                ['Total', $total],
            ]
        );

        return Command::SUCCESS;
    }
}

