<?php

declare(strict_types=1);

namespace App\Services\AI\Gemini;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Gemini AI-Powered Utility Functions
 *
 * Provides high-level AI functions built on top of the GeminiClient.
 * These functions are designed to be easy to use and extend for future AI tasks.
 */
class GeminiFunctions
{
    private GeminiClient $client;

    public function __construct(?GeminiClient $client = null)
    {
        $this->client = $client ?? new GeminiClient();
    }

    /**
     * Translate text to Persian (Farsi) using Gemini AI
     *
     * Uses a professional Persian-optimized prompt to ensure high-quality,
     * fluent translations that preserve meaning and tone.
     *
     * @param string $text The text to translate to Persian
     * @param string|null $model Optional model name. If not provided, uses the default model
     * @return string The translated text in Persian
     * @throws RuntimeException If the translation fails
     */
    public function translateToPersian(string $text, ?string $model = null): string
    {
        if (empty(trim($text))) {
            return '';
        }

        $systemInstruction = 'You are a professional Persian translator with expertise in producing '
            . 'fluent, natural translations. Your translations maintain the original meaning, tone, '
            . 'and style while sounding completely natural in Persian. You understand cultural nuances '
            . 'and adapt the translation appropriately for Persian-speaking audiences. '
            . 'Always provide only the translated text without any explanations, notes, or additional content.';

        $prompt = "Translate the following text into fluent Persian without changing its meaning or tone:\n\n{$text}";

        try {
            $translation = $this->client->generateWithSystemInstruction($prompt, $systemInstruction, $model);

            // Clean up the response - remove any potential markdown formatting or extra text
            $translation = trim($translation);
            
            // Remove common prefixes that AI might add
            $translation = preg_replace('/^(ترجمه|Translation|Persian translation):\s*/i', '', $translation);
            $translation = trim($translation);

            Log::debug('Text translated to Persian', [
                'original_length' => strlen($text),
                'translated_length' => strlen($translation),
            ]);

            return $translation;
        } catch (\Exception $e) {
            Log::error('Failed to translate text to Persian', [
                'text' => substr($text, 0, 100) . '...',
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Failed to translate text to Persian: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Summarize text using Gemini AI
     *
     * @param string $text The text to summarize
     * @param int $maxLength Optional maximum length for the summary (in words, approximate)
     * @param string|null $model Optional model name
     * @return string The summarized text
     * @throws RuntimeException If the summarization fails
     */
    public function summarize(string $text, int $maxLength = 100, ?string $model = null): string
    {
        if (empty(trim($text))) {
            return '';
        }

        $systemInstruction = 'You are an expert at creating concise, accurate summaries. '
            . 'Your summaries capture the key points and main ideas while maintaining clarity and coherence.';

        $prompt = "Summarize the following text in approximately {$maxLength} words:\n\n{$text}";

        try {
            $summary = $this->client->generateWithSystemInstruction($prompt, $systemInstruction, $model);
            return trim($summary);
        } catch (\Exception $e) {
            Log::error('Failed to summarize text', [
                'text_length' => strlen($text),
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Failed to summarize text: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Rewrite content using Gemini AI
     *
     * @param string $text The text to rewrite
     * @param string $instruction Instructions for how to rewrite (e.g., "make it more formal", "simplify the language")
     * @param string|null $model Optional model name
     * @return string The rewritten text
     * @throws RuntimeException If the rewriting fails
     */
    public function rewrite(string $text, string $instruction, ?string $model = null): string
    {
        if (empty(trim($text))) {
            return '';
        }

        $systemInstruction = 'You are a professional content writer skilled at rewriting text '
            . 'according to specific instructions while preserving the core meaning and message.';

        $prompt = "Rewrite the following text according to these instructions: {$instruction}\n\nText:\n{$text}";

        try {
            $rewritten = $this->client->generateWithSystemInstruction($prompt, $systemInstruction, $model);
            return trim($rewritten);
        } catch (\Exception $e) {
            Log::error('Failed to rewrite text', [
                'text_length' => strlen($text),
                'instruction' => $instruction,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Failed to rewrite text: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get the underlying GeminiClient instance
     * Useful for accessing advanced features or custom prompts
     *
     * @return GeminiClient The client instance
     */
    public function getClient(): GeminiClient
    {
        return $this->client;
    }
}


