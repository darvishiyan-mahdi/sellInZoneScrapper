<?php

namespace App\Services\Wordpress;

/**
 * Product Weight Detector
 * 
 * Detects approximate weight of products from their names using fuzzy matching.
 */
class ProductWeightDetector {
    /**
     * Weight mappings for different product types
     * Format: ['keyword' => [min, max]] in kg
     */
    protected array $weightMappings = [
        // Clothing
        'blazers' => [0.9, 1.2],
        'suits' => [0.9, 1.2],
        'cardigans' => [0.4, 0.7],
        'jumpers' => [0.4, 0.7],
        'hoodies' => [0.5, 0.8],
        'sweatshirts' => [0.5, 0.8],
        'jackets' => [1.0, 2.0],
        'coats' => [1.0, 2.0],
        'jeans' => [0.5, 0.7],
        'pants' => [0.4, 0.6],
        'shirts' => [0.2, 0.4],
        'shoes' => [0.5, 1.0],
        'shorts' => [0.2, 0.3],
        'sleepwear' => [0.2, 0.5],
        'loungewear' => [0.2, 0.5],
        'socks' => [0.05, 0.1],
        'sportswear' => [0.2, 0.5],
        'swimwear' => [0.1, 0.3],
        'tshirts' => [0.15, 0.3],
        't-shirts' => [0.15, 0.3],
        'underwear' => [0.1, 0.3],
        
        // Beauty products
        'foundation' => [0.05, 0.15],
        'lipstick' => [0.02, 0.05],
        'eye shadow' => [0.005, 0.05],
        'eyeshadow' => [0.005, 0.05],
        'mascara' => [0.02, 0.04],
        'creams' => [0.05, 0.25],
        'moisturizers' => [0.05, 0.25],
        'moisturiser' => [0.05, 0.25],
        'cleansers' => [0.05, 0.2],
        'face masks' => [0.03, 0.1],
        'shampoo' => [0.1, 0.5],
        'conditioner' => [0.1, 0.5],
        'hair spray' => [0.1, 0.3],
        'gel' => [0.1, 0.3],
        'perfume' => [0.05, 0.3],
        'cologne' => [0.05, 0.3],
        'brushes' => [0.005, 0.1],
        'sponges' => [0.005, 0.1],
        'trimmers' => [0.1, 0.5],
        'epilators' => [0.1, 0.5],
    ];

    /**
     * Detect weight from product name
     * 
     * @param string $name Product name/title
     * @return float Weight in kg (default: 0.3 if no match found)
     */
    public function detectWeightFromName(string $name): float
    {
        if (empty($name)) {
            return 0.3; // Default weight
        }

        // Normalize the name: lowercase, remove special characters, keep alphanumeric and spaces
        $normalized = preg_replace('/[^a-z0-9\s]/i', ' ', strtolower(trim($name)));
        $normalized = preg_replace('/\s+/', ' ', $normalized); // Normalize multiple spaces
        
        // Check each keyword in the mappings
        foreach ($this->weightMappings as $keyword => $range) {
            // Use word boundary matching for better accuracy
            $pattern = '/\b' . preg_quote($keyword, '/') . '\b/i';
            if (preg_match($pattern, $normalized)) {
                // Return average of min and max
                $average = ($range[0] + $range[1]) / 2;
                return round($average, 3);
            }
        }

        // No match found, return default
        return 0.3;
    }
}

