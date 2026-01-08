<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class NikeTestProductImages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nike:test-product-images 
                            {product_url : The Nike product URL to test}
                            {--variation_url= : Optional variation URL}
                            {--output_dir= : Optional output directory (default: storage/app/public/images/nike)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test downloading hero images from a single Nike product URL using Playwright';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $productUrl = $this->argument('product_url');
        $variationUrl = $this->option('variation_url');
        $outputDir = $this->option('output_dir') ?: storage_path('app/public/images/nike');

        $this->info("Testing Nike product images scraper");
        $this->line("Product URL: {$productUrl}");
        if ($variationUrl) {
            $this->line("Variation URL: {$variationUrl}");
        }
        $this->line("Output Directory: {$outputDir}");
        $this->newLine();

        // Validate URL
        if (!filter_var($productUrl, FILTER_VALIDATE_URL)) {
            $this->error("Invalid product URL: {$productUrl}");
            return 1;
        }

        // Check if script exists
        $scriptPath = base_path('scripts/nike-hero-images-scraper.js');
        if (!file_exists($scriptPath)) {
            $this->error("Playwright script not found at: {$scriptPath}");
            return 1;
        }

        $this->info("Starting Playwright script...");
        $this->newLine();

        // Log to Laravel
        Log::info('Starting Nike product images test', [
            'product_url' => $productUrl,
            'variation_url' => $variationUrl,
            'output_dir' => $outputDir,
        ]);

        // Build command
        $command = [
            'node',
            $scriptPath,
            $productUrl,
            $variationUrl ?: '',
            $outputDir,
        ];

        $process = new Process($command);
        $process->setTimeout(600); // 10 minutes timeout

        // Capture output in real-time and log to Laravel
        $process->run(function ($type, $buffer) use ($productUrl, $variationUrl) {
            if (Process::ERR === $type) {
                $this->error($buffer);
                Log::debug('Playwright script stderr', [
                    'product_url' => $productUrl,
                    'variation_url' => $variationUrl,
                    'output' => $buffer,
                ]);
            } else {
                $this->line($buffer);
                Log::debug('Playwright script stdout', [
                    'product_url' => $productUrl,
                    'variation_url' => $variationUrl,
                    'output' => $buffer,
                ]);
            }
        });

        // Get all output
        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();

        $this->newLine();
        $this->info("=== Process Completed ===");
        $this->line("Exit Code: " . $process->getExitCode());

        // Log completion
        Log::info('Playwright script completed', [
            'product_url' => $productUrl,
            'variation_url' => $variationUrl,
            'exit_code' => $process->getExitCode(),
            'success' => $process->isSuccessful(),
        ]);

        if (!$process->isSuccessful()) {
            $this->error("Script failed!");
            if ($stderr) {
                $this->error("Error output:");
                $this->error($stderr);
            }
            
            Log::error('Playwright script failed', [
                'product_url' => $productUrl,
                'variation_url' => $variationUrl,
                'exit_code' => $process->getExitCode(),
                'stderr' => $stderr,
                'stdout' => $stdout,
            ]);
            
            return 1;
        }

        // Try to read results JSON
        $parsedProductUrl = parse_url($productUrl);
        $productPath = $parsedProductUrl['path'] ?? '';
        $productSegments = array_filter(explode('/', $productPath));
        $productSlug = end($productSegments) ?: 'product';

        $variationId = 'default';
        if ($variationUrl) {
            $parsedVariationUrl = parse_url($variationUrl);
            $variationPath = $parsedVariationUrl['path'] ?? '';
            $variationSegments = array_filter(explode('/', $variationPath));
            $variationId = end($variationSegments) ?: 'default';
        }

        $resultsPath = $outputDir . '/' . $productSlug . '/' . $variationId . '/results.json';

        if (file_exists($resultsPath)) {
            $this->newLine();
            $this->info("=== Results ===");
            $resultsJson = file_get_contents($resultsPath);
            $results = json_decode($resultsJson, true);

            if (isset($results['images']) && is_array($results['images'])) {
                $imageCount = count($results['images']);
                $this->info("Total images found: " . $imageCount);
                $this->newLine();

                // Log images summary
                Log::info('Product images test results', [
                    'product_url' => $productUrl,
                    'variation_url' => $variationUrl,
                    'total_images' => $imageCount,
                    'results_path' => $resultsPath,
                ]);

                foreach ($results['images'] as $index => $image) {
                    $this->line("Image #{$index}:");
                    $this->line("  URL: " . ($image['heroImgSrc'] ?? 'N/A'));
                    if (isset($image['localPath'])) {
                        $this->line("  Local: " . $image['localPath']);
                    }
                    if (isset($image['thumbnailTestId'])) {
                        $this->line("  Thumbnail: " . $image['thumbnailTestId']);
                    }
                    $this->newLine();
                    
                    // Log each image
                    Log::debug('Image extracted', [
                        'product_url' => $productUrl,
                        'variation_url' => $variationUrl,
                        'index' => $index,
                        'hero_img_src' => $image['heroImgSrc'] ?? null,
                        'local_path' => $image['localPath'] ?? null,
                        'thumbnail_test_id' => $image['thumbnailTestId'] ?? null,
                    ]);
                }
            } else {
                $this->warn("No images found in results");
                Log::warning('No images found in results', [
                    'product_url' => $productUrl,
                    'variation_url' => $variationUrl,
                    'results_path' => $resultsPath,
                ]);
            }

            $this->info("Results JSON saved to: {$resultsPath}");
        } else {
            $this->warn("Results file not found at: {$resultsPath}");
            Log::warning('Results file not found', [
                'product_url' => $productUrl,
                'variation_url' => $variationUrl,
                'results_path' => $resultsPath,
            ]);
        }

        return 0;
    }
}

