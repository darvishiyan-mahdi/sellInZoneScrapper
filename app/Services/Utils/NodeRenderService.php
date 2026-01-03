<?php

declare(strict_types=1);

namespace App\Services\Utils;

use Exception;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

class NodeRenderService {
    private const DEFAULT_TIMEOUT = 300; // 5 minutes
    private const MAX_RETRIES     = 3;
    private const DEFAULT_WAIT_SELECTOR = '.js-cdp-current-count';

    /**
     * Get rendered HTML content from a URL using Puppeteer.
     *
     * @param string      $url         The URL to render
     * @param string|null $waitSelector Optional CSS selector to wait for before returning HTML
     * @param int         $timeout     Timeout in seconds
     * @param int         $retries     Number of retry attempts
     *
     * @return string The rendered HTML content
     * @throws RuntimeException If rendering fails after all retries
     */
    public function getRenderedHtml(string $url, ?string $waitSelector = self::DEFAULT_WAIT_SELECTOR, int $timeout = self::DEFAULT_TIMEOUT, int $retries = self::MAX_RETRIES): string {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $retries) {
            $attempt++;

            try {
                $scriptPath = base_path('scripts/puppeteer-render.js');
                if (!file_exists($scriptPath)) {
                    throw new RuntimeException("Puppeteer script not found at: {$scriptPath}");
                }

                $command = [
                    'node',
                    $scriptPath,
                    $url,
                ];

                if ($waitSelector) {
                    $command[] = $waitSelector;
                }

                $command[] = (string)($timeout * 1000); // Convert to milliseconds

                // Use Process constructor with array for better cross-platform compatibility
                $process = new Process($command);
                $process->setTimeout($timeout + 10); // Add buffer to process timeout (300 seconds + 10 buffer)
                $process->run();

                if (!$process->isSuccessful()) {
                    $errorOutput = $process->getErrorOutput();
                    $exitCode = $process->getExitCode();

                    Log::warning('Puppeteer process failed', [
                        'url' => $url,
                        'exit_code' => $exitCode,
                        'error' => $errorOutput,
                        'attempt' => $attempt,
                    ]);

                    // Check if it's a retryable error
                    if ($this->isRetryableError($errorOutput, $exitCode) && $attempt < $retries) {
                        $waitTime = $this->calculateBackoff($attempt);
                        Log::info("Retrying after backoff", [
                            'url' => $url,
                            'attempt' => $attempt,
                            'wait_seconds' => $waitTime,
                        ]);
                        sleep($waitTime);
                        continue;
                    }

                    throw new RuntimeException("Puppeteer process failed: {$errorOutput} (exit code: {$exitCode})");
                }

                $html = $process->getOutput();

                if (empty(trim($html))) {
                    throw new RuntimeException("Puppeteer returned empty HTML");
                }

                // Check if the HTML contains Cloudflare challenge
                if ($this->isCloudflareChallenge($html)) {
                    Log::warning('Cloudflare challenge detected', [
                        'url' => $url,
                        'attempt' => $attempt,
                    ]);

                    if ($attempt < $retries) {
                        $waitTime = $this->calculateBackoff($attempt);
                        Log::info("Retrying after backoff (Cloudflare challenge)", [
                            'url' => $url,
                            'attempt' => $attempt,
                            'wait_seconds' => $waitTime,
                        ]);
                        sleep($waitTime);
                        continue;
                    }

                    throw new RuntimeException("Cloudflare challenge detected and not resolved after {$retries} attempts");
                }

                if ($attempt > 1) {
                    Log::info('Successfully rendered page after retry', [
                        'url' => $url,
                        'attempts' => $attempt,
                    ]);
                }

                return $html;

            } catch (Exception $e) {
                $lastException = $e;
                $errorMessage = $e->getMessage();

                Log::warning('Error rendering page with Puppeteer', [
                    'url' => $url,
                    'error' => $errorMessage,
                    'attempt' => $attempt,
                    'exception_class' => get_class($e),
                ]);

                if ($attempt < $retries) {
                    $waitTime = $this->calculateBackoff($attempt);
                    sleep($waitTime);
                    continue;
                }
            }
        }

        throw new RuntimeException(
            "Failed to render page after {$retries} attempts: " . ($lastException ? $lastException->getMessage() : 'Unknown error'),
            0,
            $lastException
        );
    }

    /**
     * Check if the error is retryable.
     */
    private function isRetryableError(string $errorOutput, int $exitCode): bool {
        // Network errors, timeouts, etc. are retryable
        $retryablePatterns = [
            'timeout',
            'net::ERR',
            'ERR_NAME_NOT_RESOLVED',
            'ERR_INTERNET_DISCONNECTED',
            'ECONNREFUSED',
            'ETIMEDOUT',
            'ENOTFOUND',
        ];

        foreach ($retryablePatterns as $pattern) {
            if (stripos($errorOutput, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if HTML contains Cloudflare challenge.
     */
    private function isCloudflareChallenge(string $html): bool {
        $cloudflareIndicators = [
            'cf-browser-verification',
            'challenge-platform',
            'cf-error-details',
            'Checking your browser before accessing',
            'Just a moment',
        ];

        foreach ($cloudflareIndicators as $indicator) {
            if (stripos($html, $indicator) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get rendered HTML for product detail page with color swatch interactions.
     *
     * @param string $url     The product URL to render
     * @param int    $timeout Timeout in seconds
     * @param int    $retries Number of retry attempts
     *
     * @return string The rendered HTML content
     * @throws RuntimeException If rendering fails after all retries
     */
    public function getProductDetailHtml(string $url, int $timeout = self::DEFAULT_TIMEOUT, int $retries = self::MAX_RETRIES): string {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $retries) {
            $attempt++;

            try {
                $scriptPath = base_path('scripts/puppeteer-product-detail.js');
                if (!file_exists($scriptPath)) {
                    throw new RuntimeException("Puppeteer product detail script not found at: {$scriptPath}");
                }

                $command = [
                    'node',
                    $scriptPath,
                    $url,
                    (string)($timeout * 1000), // Convert to milliseconds
                ];

                $process = new Process($command);
                $process->setTimeout($timeout + 10);
                $process->run();

                if (!$process->isSuccessful()) {
                    $errorOutput = $process->getErrorOutput();
                    $exitCode = $process->getExitCode();

                    // Check for network errors
                    $isNetworkError = strpos($errorOutput, 'ERR_NAME_NOT_RESOLVED') !== false ||
                                     strpos($errorOutput, 'ERR_INTERNET_DISCONNECTED') !== false ||
                                     strpos($errorOutput, 'net::ERR') !== false;

                    Log::warning('Puppeteer product detail process failed', [
                        'url' => $url,
                        'exit_code' => $exitCode,
                        'error' => $errorOutput,
                        'attempt' => $attempt,
                        'is_network_error' => $isNetworkError,
                    ]);

                    // Network errors are retryable
                    if (($isNetworkError || $this->isRetryableError($errorOutput, $exitCode)) && $attempt < $retries) {
                        $waitTime = $this->calculateBackoff($attempt);
                        if ($isNetworkError) {
                            $waitTime = max($waitTime, 10); // Longer wait for network errors
                        }
                        Log::info("Retrying product detail after backoff", [
                            'url' => $url,
                            'attempt' => $attempt,
                            'wait_seconds' => $waitTime,
                            'reason' => $isNetworkError ? 'network_error' : 'retryable_error',
                        ]);
                        sleep($waitTime);
                        continue;
                    }

                    // If it's a network error and we've exhausted retries, throw a more descriptive error
                    if ($isNetworkError) {
                        throw new RuntimeException("Network error while fetching product detail: DNS resolution failed or network disconnected. URL: {$url}");
                    }

                    throw new RuntimeException("Puppeteer product detail process failed: {$errorOutput} (exit code: {$exitCode})");
                }

                $html = $process->getOutput();
                $errorOutput = $process->getErrorOutput();

                // Extract and log price debug info from stderr
                if (preg_match_all('/LULULEMON_PRICE_DEBUG_START\s*\n(.*?)\nLULULEMON_PRICE_DEBUG_END/s', $errorOutput, $priceDebugMatches)) {
                    foreach ($priceDebugMatches[1] as $priceDebugJson) {
                        try {
                            $priceDebug = json_decode($priceDebugJson, true);
                            if ($priceDebug) {
                                Log::info('Puppeteer price extraction debug', [
                                    'url' => $url,
                                    'color' => $priceDebug['color'] ?? 'unknown',
                                    'debug' => $priceDebug['debug'] ?? [],
                                    'extracted' => $priceDebug['extracted'] ?? [],
                                ]);
                            }
                        } catch (Exception $e) {
                            Log::debug('Failed to parse price debug from stderr', ['error' => $e->getMessage()]);
                        }
                    }
                }

                // Extract color variations data from stderr if present
                if (preg_match('/LULULEMON_COLOR_VARIATIONS_START\s*\n(.*?)\nLULULEMON_COLOR_VARIATIONS_END/s', $errorOutput, $matches)) {
                    try {
                        $colorVariationsData = json_decode($matches[1], true);
                        if ($colorVariationsData) {
                            // Inject into HTML as a script tag if not already present
                            if (strpos($html, 'lululemon-color-variations-data') === false) {
                                $scriptTag = '<script id="lululemon-color-variations-data" type="text/javascript" data-variations="' . 
                                           htmlspecialchars(json_encode($colorVariationsData), ENT_QUOTES, 'UTF-8') . '">' .
                                           'window.__LULULEMON_COLOR_VARIATIONS__ = ' . json_encode($colorVariationsData) . ';' .
                                           '</script>';
                                $html = str_replace('</head>', $scriptTag . '</head>', $html);
                                if (strpos($html, '</head>') === false) {
                                    $html = $scriptTag . $html;
                                }
                            }
                        }
                    } catch (Exception $e) {
                        Log::debug('Failed to parse color variations from stderr', ['error' => $e->getMessage()]);
                    }
                }

                if (empty(trim($html))) {
                    throw new RuntimeException("Puppeteer returned empty HTML");
                }

                if ($this->isCloudflareChallenge($html)) {
                    Log::warning('Cloudflare challenge detected in product detail', [
                        'url' => $url,
                        'attempt' => $attempt,
                    ]);

                    if ($attempt < $retries) {
                        // Longer wait for Cloudflare challenges
                        $waitTime = $this->calculateBackoff($attempt) * 2; // Double the wait time for Cloudflare
                        Log::info("Waiting for Cloudflare challenge to resolve", [
                            'url' => $url,
                            'attempt' => $attempt,
                            'wait_seconds' => $waitTime,
                        ]);
                        sleep($waitTime);
                        continue;
                    }

                    throw new RuntimeException("Cloudflare challenge detected and not resolved after {$retries} attempts");
                }

                return $html;

            } catch (Exception $e) {
                $lastException = $e;
                $errorMessage = $e->getMessage();

                Log::warning('Error rendering product detail with Puppeteer', [
                    'url' => $url,
                    'error' => $errorMessage,
                    'attempt' => $attempt,
                    'exception_class' => get_class($e),
                ]);

                if ($attempt < $retries) {
                    $waitTime = $this->calculateBackoff($attempt);
                    sleep($waitTime);
                    continue;
                }
            }
        }

        throw new RuntimeException(
            "Failed to render product detail page after {$retries} attempts: " . ($lastException ? $lastException->getMessage() : 'Unknown error'),
            0,
            $lastException
        );
    }

    /**
     * Calculate exponential backoff time in seconds.
     */
    private function calculateBackoff(int $attempt): int {
        // Exponential backoff: 2^attempt seconds, with jitter
        $baseWait = pow(2, $attempt);
        $jitter = rand(1, 3); // Add 1-3 seconds of jitter

        return (int)($baseWait + $jitter);
    }
}

