<?php

declare(strict_types=1);

namespace App\Services\AI\Gemini;

use GeminiAPI\Client;
use GeminiAPI\GenerationConfig;
use GeminiAPI\Resources\Parts\TextPart;
use GeminiAPI\Responses\GenerateContentResponse;
use Illuminate\Support\Facades\Log;
use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * Gemini API Client Service
 *
 * Handles all interactions with the Google Gemini API.
 * Provides a clean, reusable interface for sending prompts and receiving responses.
 */
class GeminiClient
{
    private Client $client;
    private string $defaultModel;

    public function __construct(?string $apiKey = null, ?string $defaultModel = null)
    {
        $apiKey = $apiKey ?? config('services.gemini.api_key') ?? env('GEMINI_API_KEY');

        if (empty($apiKey)) {
            throw new RuntimeException('Gemini API key is required. Set GEMINI_API_KEY in your .env file.');
        }

        $this->client = new Client($apiKey);
        $modelFromConfig = $defaultModel ?? config('services.gemini.default_model', 'gemini-2.5-flash');
        $this->defaultModel = $this->normalizeModelName($modelFromConfig);
    }

    /**
     * Generate content from a prompt using the Gemini API
     *
     * @param string $prompt The text prompt to send to Gemini
     * @param string|null $model Optional model name. If not provided, uses the default model
     * @param array<string, mixed> $options Additional options for generation (temperature, maxTokens, etc.)
     * @return string The generated text response
     * @throws RuntimeException If the API request fails
     */
    public function generate(string $prompt, ?string $model = null, array $options = []): string
    {
        try {
            $modelName = $this->normalizeModelName($model ?? $this->defaultModel);
            $generativeModel = $this->client->generativeModel($modelName);

            // Apply generation config if options are provided
            if (!empty($options)) {
                $generationConfig = new GenerationConfig();
                
                if (isset($options['temperature'])) {
                    $generationConfig = $generationConfig->withTemperature((float) $options['temperature']);
                }
                
                if (isset($options['maxTokens'])) {
                    $generationConfig = $generationConfig->withMaxOutputTokens((int) $options['maxTokens']);
                }
                
                if (isset($options['topK'])) {
                    $generationConfig = $generationConfig->withTopK((int) $options['topK']);
                }
                
                if (isset($options['topP'])) {
                    $generationConfig = $generationConfig->withTopP((float) $options['topP']);
                }

                $generativeModel = $generativeModel->withGenerationConfig($generationConfig);
            }

            $response = $generativeModel->generateContent(new TextPart($prompt));

            return $this->extractTextFromResponse($response);
        } catch (ClientExceptionInterface $e) {
            Log::error('Gemini API request failed', [
                'prompt' => $prompt,
                'model' => $model ?? $this->defaultModel,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Failed to generate content from Gemini API: ' . $e->getMessage(), 0, $e);
        } catch (\Exception $e) {
            Log::error('Unexpected error in Gemini API request', [
                'prompt' => $prompt,
                'model' => $model ?? $this->defaultModel,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Unexpected error while generating content: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Generate content with a system instruction
     *
     * @param string $prompt The user prompt
     * @param string $systemInstruction The system instruction to guide the model's behavior
     * @param string|null $model Optional model name
     * @return string The generated text response
     * @throws RuntimeException If the API request fails
     */
    public function generateWithSystemInstruction(
        string $prompt,
        string $systemInstruction,
        ?string $model = null
    ): string {
        // Normalize the requested model to RAW format (strip models/ prefix)
        $requested = $this->normalizeModelName($model ?? $this->defaultModel);
        
        Log::debug('Starting Gemini translation with system instruction', [
            'requested_model' => $requested,
            'original_input' => $model ?? $this->defaultModel,
        ]);
        
        // Build fallback list with RAW model names (no models/ prefix)
        $fallbackModels = [
            'gemini-2.5-flash',
            'gemini-2.5-pro',
            'gemini-flash-latest',
            'gemini-pro-latest',
        ];
        
        // Normalize all fallbacks and build deduplicated list
        $modelsToTry = [$requested];
        foreach ($fallbackModels as $fallback) {
            $normalizedFallback = $this->normalizeModelName($fallback);
            if (!in_array($normalizedFallback, $modelsToTry, true)) {
                $modelsToTry[] = $normalizedFallback;
            }
        }
        
        $lastException = null;
        
        foreach ($modelsToTry as $tryModel) {
            try {
                $generativeModel = $this->client
                    ->withV1BetaVersion()
                    ->generativeModel($tryModel)
                    ->withSystemInstruction($systemInstruction);

                $response = $generativeModel->generateContent(new TextPart($prompt));

                // If we used a fallback model, log it
                if ($tryModel !== $requested) {
                    Log::info('Used fallback model for system instruction', [
                        'requested_model' => $requested,
                        'used_model' => $tryModel,
                    ]);
                }

                return $this->extractTextFromResponse($response);
            } catch (ClientExceptionInterface $e) {
                $lastException = $e;
                
                // Check if this is a 404/model-not-found error and we have more models to try
                $is404 = str_contains($e->getMessage(), '404') || 
                        str_contains($e->getMessage(), 'NOT_FOUND') ||
                        str_contains($e->getMessage(), 'not found');
                
                $isLastModel = ($tryModel === end($modelsToTry));
                
                if ($is404 && !$isLastModel) {
                    Log::warning('Model not available in v1beta, trying fallback', [
                        'model' => $tryModel,
                        'error' => $e->getMessage(),
                        'next_fallback' => $modelsToTry[array_search($tryModel, $modelsToTry, true) + 1] ?? 'none',
                    ]);
                    continue;
                }
                
                // If this is the last model or not a 404, log and rethrow
                Log::error('Gemini API request with system instruction failed', [
                    'prompt' => $prompt,
                    'system_instruction' => $systemInstruction,
                    'model' => $tryModel,
                    'error' => $e->getMessage(),
                ]);

                throw new RuntimeException(
                    'Failed to generate content from Gemini API with system instruction: ' . $e->getMessage(),
                    0,
                    $e
                );
            } catch (\Exception $e) {
                $lastException = $e;
                
                // Check if this is a 404/model-not-found error and we have more models to try
                $is404 = str_contains($e->getMessage(), '404') || 
                        str_contains($e->getMessage(), 'NOT_FOUND') ||
                        str_contains($e->getMessage(), 'not found');
                
                $isLastModel = ($tryModel === end($modelsToTry));
                
                if ($is404 && !$isLastModel) {
                    Log::warning('Model not available in v1beta, trying fallback', [
                        'model' => $tryModel,
                        'error' => $e->getMessage(),
                        'next_fallback' => $modelsToTry[array_search($tryModel, $modelsToTry, true) + 1] ?? 'none',
                    ]);
                    continue;
                }
                
                Log::error('Unexpected error in Gemini API request with system instruction', [
                    'prompt' => $prompt,
                    'system_instruction' => $systemInstruction,
                    'model' => $tryModel,
                    'error' => $e->getMessage(),
                ]);

                throw new RuntimeException(
                    'Unexpected error while generating content with system instruction: ' . $e->getMessage(),
                    0,
                    $e
                );
            }
        }
        
        // If we get here, all models failed
        if ($lastException) {
            throw new RuntimeException(
                'Failed to generate content from Gemini API with system instruction after trying all models: ' . $lastException->getMessage(),
                0,
                $lastException
            );
        }
        
        throw new RuntimeException('No models available to try');
    }

    /**
     * Extract text from a GenerateContentResponse
     *
     * @param GenerateContentResponse $response The API response
     * @return string The extracted text
     * @throws RuntimeException If the response doesn't contain text
     */
    private function extractTextFromResponse(GenerateContentResponse $response): string
    {
        try {
            return $response->text();
        } catch (\ValueError $e) {
            // Check if there's prompt feedback indicating why the response was blocked
            if ($response->promptFeedback !== null) {
                $blockReason = $response->promptFeedback->blockReason ?? 'Unknown reason';
                throw new RuntimeException(
                    "Gemini API blocked the response. Reason: {$blockReason}",
                    0,
                    $e
                );
            }

            throw new RuntimeException(
                'Failed to extract text from Gemini API response: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Get the underlying Gemini API client
     * Useful for advanced use cases that require direct access
     *
     * @return Client The Gemini API client instance
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * Get the default model being used
     *
     * @return string The default model name
     */
    public function getDefaultModel(): string
    {
        return $this->defaultModel;
    }

    /**
     * Normalize model name to RAW format (strip models/ prefix)
     * 
     * The Gemini PHP SDK adds the "models/" prefix internally, so we must
     * pass raw model names like "gemini-2.5-flash" not "models/gemini-2.5-flash"
     *
     * @param string|null $model The model name to normalize (can be null/empty)
     * @return string The normalized RAW model name without "models/" prefix
     */
    private function normalizeModelName(?string $model): string
    {
        // If null/empty, use default (already normalized)
        if (empty($model)) {
            return $this->defaultModel;
        }
        
        $model = trim($model);
        
        // Strip all leading "models/" prefixes (handles cases like "models/models/gemini-...")
        while (str_starts_with($model, 'models/')) {
            $model = substr($model, 7); // Remove "models/" (7 characters)
        }
        
        // Return the raw model name (e.g., "gemini-2.5-flash")
        return $model;
    }
}

