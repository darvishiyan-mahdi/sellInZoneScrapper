# Nike Hero Images Scraper

A robust Playwright automation script to extract Nike product detail page (PDP) hero images by clicking through thumbnails.

## Quick Test

To test with a single product URL, you have two options:

### Option 1: Artisan Command (Recommended)
```bash
php artisan nike:test-product-images "https://www.nike.com/ca/t/shox-r4-shoes-pMCg79/AR3565-101"
```

With optional variation URL:
```bash
php artisan nike:test-product-images "https://www.nike.com/ca/t/shox-r4-shoes-pMCg79/AR3565-101" --variation_url="https://www.nike.com/ca/t/shox-r4-shoes-pMCg79/AR3565-004"
```

### Option 2: Direct Node.js Script
```bash
node scripts/test-nike-product.js "https://www.nike.com/ca/t/shox-r4-shoes-pMCg79/AR3565-101"
```

With variation URL:
```bash
node scripts/test-nike-product.js "https://www.nike.com/ca/t/shox-r4-shoes-pMCg79/AR3565-101" "https://www.nike.com/ca/t/shox-r4-shoes-pMCg79/AR3565-004"
```

## Features

- **Dynamic thumbnail discovery**: Automatically finds all thumbnails using `[data-testid^="Thumbnail-"]`
- **Smart scrolling**: Scrolls thumbnails into view before clicking
- **Robust waiting**: Waits for hero image to update after each thumbnail click
- **Duplicate detection**: Skips duplicate images automatically
- **Error handling**: Retry logic for flaky operations
- **Edge case handling**: Works even when thumbnails are missing or only one image exists

## Installation

```bash
# Install Playwright
npm install playwright

# Install Playwright browsers (one-time setup)
npx playwright install chromium
```

## Usage

The script is now **dynamic** and accepts command-line arguments for product URL, variation URL (optional), and output directory (optional).

### TypeScript Version

```bash
# Install TypeScript and ts-node if not already installed
npm install -g typescript ts-node

# Scrape a single product
npx ts-node scripts/nike-hero-images-scraper.ts "https://www.nike.com/ca/t/product/CODE"

# Scrape a product variation (color variant)
npx ts-node scripts/nike-hero-images-scraper.ts "https://www.nike.com/ca/t/product/CODE" "https://www.nike.com/ca/t/product/CODE-002"

# Specify custom output directory
npx ts-node scripts/nike-hero-images-scraper.ts "https://www.nike.com/ca/t/product/CODE" "" "./images/nike"
```

### JavaScript Version

```bash
# Scrape a single product
node scripts/nike-hero-images-scraper.js "https://www.nike.com/ca/t/product/CODE"

# Scrape a product variation (color variant)
node scripts/nike-hero-images-scraper.js "https://www.nike.com/ca/t/product/CODE" "https://www.nike.com/ca/t/product/CODE-002"

# Specify custom output directory
node scripts/nike-hero-images-scraper.js "https://www.nike.com/ca/t/product/CODE" "" "./images/nike"
```

### Calling from PHP/Laravel

You can call this script from your PHP scraper:

```php
$productUrl = "https://www.nike.com/ca/t/product/CODE";
$variationUrl = "https://www.nike.com/ca/t/product/CODE-002"; // optional
$outputDir = storage_path('app/public/images/nike');

$command = sprintf(
    'node %s "%s" "%s" "%s"',
    base_path('scripts/nike-hero-images-scraper.js'),
    escapeshellarg($productUrl),
    escapeshellarg($variationUrl ?: ''),
    escapeshellarg($outputDir)
);

exec($command, $output, $returnCode);
```

### Configuration Options

You can modify the `CONFIG` object to adjust behavior:

- `navigationTimeout`: Timeout for page navigation (default: 30000ms)
- `heroImageUpdateTimeout`: Timeout for waiting hero image to update (default: 5000ms)
- `clickDelay`: Delay between thumbnail clicks (default: 500ms)
- `maxRetries`: Maximum retry attempts for flaky operations (default: 3)
- `retryDelay`: Wait time between retries (default: 1000ms)
- `headless`: Run browser in headless mode (default: true, set to false to see browser)

## Output

The script:
1. **Downloads all hero images** to disk in organized folders
2. **Generates a results JSON file** with metadata and image paths

### Folder Structure

Images are saved in: `outputDir/productSlug/variationId/`

Example:
```
storage/app/public/images/nike/
  └── IB0612-001/
      ├── default/
      │   ├── hero-0.jpg
      │   ├── hero-1.jpg
      │   ├── hero-2.jpg
      │   └── results.json
      └── IB0612-003/
          ├── hero-0.jpg
          ├── hero-1.jpg
          └── results.json
```

### Results JSON

Saved in: `outputDir/productSlug/variationId/results.json`

```json
{
  "timestamp": "2026-01-05T...",
  "productUrl": "https://www.nike.com/ca/t/product/CODE",
  "variationUrl": "https://www.nike.com/ca/t/product/CODE-002",
  "totalImages": 5,
  "images": [
    {
      "productUrl": "https://...",
      "variationUrl": "https://...",
      "index": 0,
      "heroImgSrc": "https://...",
      "localPath": "storage/app/public/images/nike/CODE/CODE-002/hero-0.jpg",
      "thumbnailTestId": null
    },
    {
      "productUrl": "https://...",
      "variationUrl": "https://...",
      "index": 1,
      "heroImgSrc": "https://...",
      "localPath": "storage/app/public/images/nike/CODE/CODE-002/hero-1.jpg",
      "thumbnailTestId": "Thumbnail-0"
    }
  ]
}
```

## How It Works

1. **Page Navigation**: Opens each product URL and waits for the hero image container to load
2. **Initial Image**: Captures the initial hero image (index 0) without clicking
3. **Thumbnail Discovery**: Finds all thumbnails dynamically using `[data-testid^="Thumbnail-"]`
4. **Thumbnail Clicking**: For each thumbnail:
   - Scrolls the thumbnail into view
   - Clicks the thumbnail wrapper div
   - Waits for the hero image to update
   - Extracts the new hero image URL
5. **Deduplication**: Skips duplicate images automatically
6. **Error Handling**: Retries failed operations up to `maxRetries` times

## Requirements

- Node.js 14+ 
- Playwright
- TypeScript (for TypeScript version)

## Troubleshooting

### Thumbnails not found
- The script will still capture the initial hero image
- Check if the page structure has changed

### Hero image not updating
- Increase `heroImageUpdateTimeout` in CONFIG
- Check browser console for JavaScript errors (set `headless: false`)

### Timeout errors
- Increase `navigationTimeout` in CONFIG
- Check your internet connection

## Notes

- The script uses the same click behavior as the site: clicking the wrapper div with `data-testid="Thumbnail-X"`
- Hero images are identified by `data-testid="HeroImg"` with `aria-hidden="false"` for visible images
- Falls back to the last HeroImg in the container if no visible one is found
- All images are deduplicated to avoid storing the same image multiple times

npx playwright install