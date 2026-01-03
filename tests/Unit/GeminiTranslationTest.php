<?php

namespace Tests\Unit;

use App\Services\AI\Gemini\GeminiFunctions;
use Tests\TestCase;

class GeminiTranslationTest extends TestCase
{

    /**
     * Smoke test for Persian translation functionality
     * 
     * This test verifies that the translateToPersian method works
     * with the default model configuration and doesn't throw exceptions
     * for basic translation requests.
     */
    public function test_translate_to_persian_smoke_test(): void
    {
        // Skip if API key is not configured
        if (empty(env('GEMINI_API_KEY'))) {
            $this->markTestSkipped('GEMINI_API_KEY is not configured');
        }

        $geminiFunctions = new GeminiFunctions();
        
        // Test with a simple English phrase
        $result = $geminiFunctions->translateToPersian('Hello');
        
        // Assert that we got a non-empty result
        $this->assertNotEmpty($result);
        $this->assertIsString($result);
        
        // The result should be different from the input (it's translated)
        $this->assertNotEquals('Hello', $result);
    }
}

