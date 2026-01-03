<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Wordpress\WordpressSyncService;
use Exception;
use Illuminate\Console\Command;

class SyncWordpressProductsCommand extends Command {
    protected $signature = 'wordpress:sync
                            {product_id? : Specific product ID to sync}
                            {--force : Force sync even if already synced}
                            {--status= : Filter by product status (default: active)}';

    protected $description = 'Sync products to WooCommerce (supports variable products with variations)';

    public function handle (WordpressSyncService $syncService) : int {
        $productId = $this->argument('product_id');
        $force     = $this->option('force');
        $status    = $this->option('status') ?? 'active';

        if ( $productId ) {
            $product = Product::where('id', $productId)->first();

            if ( !$product ) {
                $this->error("Product not found: {$productId}");

                return Command::FAILURE;
            }

            if ( !$force && $product->status !== $status ) {
                $this->warn("Product status is '{$product->status}', not '{$status}'. Use --force to sync anyway.");

                return Command::FAILURE;
            }

            $this->syncSingleProduct($product, $syncService);
        } else {
            $this->syncAllProducts($syncService, $status, $force);
        }

        return Command::SUCCESS;
    }

    protected function syncSingleProduct (Product $product, WordpressSyncService $syncService) : void {
        $this->info("Syncing product: {$product->title} (ID: {$product->id})");

        try {
            $syncService->syncProduct($product);
            $this->info("✓ Successfully synced product: {$product->title}");
        } catch ( Exception $e ) {
            $this->error("✗ Failed to sync product: {$e->getMessage()}");
            if ( $this->option('verbose') ) {
                $this->error($e->getTraceAsString());
            }
        }
    }

    protected function syncAllProducts (WordpressSyncService $syncService, string $status, bool $force) : void {
        $query = Product::where('status', $status);

        if ( !$force ) {
            $query->where(function ($q) {
                $q->whereDoesntHave('wordpressMapping')->orWhereHas('wordpressMapping', function ($subQ) {
                        $subQ->whereColumn('products.updated_at', '>', 'product_wordpress_mappings.last_synced_at')
                             ->orWhereNull('product_wordpress_mappings.last_synced_at');
                    });
            });
        }

        $total = $query->count();

        if ( $total === 0 ) {
            $this->info('No products to sync.');

            return;
        }

        $this->info("Found {$total} products to sync.");

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $bar->setMessage('Starting sync...');
        $bar->start();

        $successCount = 0;
        $failCount    = 0;

        $query->chunk(50, function ($products) use ($syncService, $bar, &$successCount, &$failCount) {
            foreach ( $products as $product ) {
                try {
                    $syncService->syncProduct($product);
                    $successCount++;
                    $bar->setMessage("Synced: {$product->title}");
                } catch ( Exception $e ) {
                    $failCount++;
                    $bar->setMessage("Failed: {$product->title} - " . substr($e->getMessage(), 0, 50));
                    if ( $this->option('verbose') ) {
                        $this->newLine();
                        $this->warn("Failed to sync product {$product->id}: {$e->getMessage()}");
                    }
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Sync completed. Success: {$successCount}, Failed: {$failCount}");
    }
}

