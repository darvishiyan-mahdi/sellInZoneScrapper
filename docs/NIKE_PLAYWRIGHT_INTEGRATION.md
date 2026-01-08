# Nike Playwright Integration Guide

This document explains how the Playwright script integrates with the PHP scraper to extract and download hero images.

## Overview

The PHP scraper (`NikeProductDetailScraper.php`) automatically calls the Playwright script (`nike-hero-images-scraper.js`) for each product and variation to extract all hero images by clicking through thumbnails.

## How It Works

### 1. PHP Scraper Flow

When scraping a Nike product, the PHP scraper:

1. **Fetches the main product page** using `Http::get()`
2. **Extracts color links** from the page
3. **For each color variation:**
   - Fetches the color variant page
   - **Calls Playwright script** to extract hero images
   - Merges Playwright images with HTML-extracted images
4. **Saves all images** to the database

### 2. Integration Point

The integration happens in `NikeProductDetailScraper::parseAndSaveProduct()`:

```php
// For each color variant
$playwrightImages = $this->extractHeroImagesWithPlaywright($url, $colorUrl);
if (!empty($playwrightImages)) {
    // Merge Playwright images with extracted images
    $colorData['images'] = array_merge($playwrightImages, $colorData['images']);
    $colorData['images_playwright'] = $playwrightImages;
}
```

### 3. Playwright Script Execution

The `extractHeroImagesWithPlaywright()` method:

1. **Builds the command:**
   ```php
   $command = [
       'node',
       base_path('scripts/nike-hero-images-scraper.js'),
       $productUrl,
       $variationUrl ?: '',
       storage_path('app/public/images/nike'),
   ];
   ```

2. **Executes using Symfony Process:**
   ```php
   $process = new Process($command);
   $process->setTimeout(120); // 2 minutes
   $process->run();
   ```

3. **Reads the results JSON:**
   - Path: `storage/app/public/images/nike/{productSlug}/{variationId}/results.json`
   - Contains array of images with `heroImgSrc` and `localPath`

4. **Converts to PHP format:**
   - Maps Playwright results to PHP image array format
   - Includes both remote URL and local path

## File Structure

```
storage/app/public/images/nike/
  └── IB0612-001/              (product slug from URL)
      ├── default/             (main product)
      │   ├── hero-0.jpg
      │   ├── hero-1.jpg
      │   ├── hero-2.jpg
      │   └── results.json
      └── IB0612-003/          (color variation)
          ├── hero-0.jpg
          ├── hero-1.jpg
          └── results.json
```

## Data Flow

```
PHP Scraper
    ↓
extractHeroImagesWithPlaywright()
    ↓
Execute: node nike-hero-images-scraper.js <productUrl> <variationUrl> <outputDir>
    ↓
Playwright Script
    ├── Opens product page
    ├── Clicks through thumbnails
    ├── Downloads each hero image
    └── Saves results.json
    ↓
PHP reads results.json
    ↓
Converts to image array format
    ↓
Merges with HTML-extracted images
    ↓
Saves to database
```

## Configuration

### PHP Side

- **Script Path:** `base_path('scripts/nike-hero-images-scraper.js')`
- **Output Directory:** `storage_path('app/public/images/nike')`
- **Timeout:** 120 seconds (2 minutes)
- **Error Handling:** Logs warnings and continues if Playwright fails

### Node.js Side

- **Timeout:** 30 seconds navigation, 5 seconds per thumbnail
- **Retries:** 3 attempts for flaky operations
- **Headless:** true (runs in background)

## Error Handling

The integration is **fault-tolerant**:

- If Playwright script fails → PHP continues with HTML-extracted images
- If results.json not found → Returns empty array, continues scraping
- If script timeout → Logs error, continues with other images
- All errors are logged for debugging

## Usage Example

When you run:

```bash
php artisan scrapers:nike:scrape-category
```

The scraper will:

1. Collect product URLs from API
2. For each product:
   - Fetch main page HTML
   - Extract color links
   - For each color:
     - **Call Playwright script** → Downloads hero images
     - Extract other data (price, sizes, description)
   - Save everything to database

## Manual Testing

You can test the Playwright script independently:

```bash
# Test single product
node scripts/nike-hero-images-scraper.js "https://www.nike.com/ca/t/product/CODE"

# Test with variation
node scripts/nike-hero-images-scraper.js "https://www.nike.com/ca/t/product/CODE" "https://www.nike.com/ca/t/product/CODE-002"
```

## Troubleshooting

### Playwright script not found
- Check: `scripts/nike-hero-images-scraper.js` exists
- Check: Node.js is installed (`node --version`)

### Script timeout
- Increase timeout in `extractHeroImagesWithPlaywright()` (line 1247)
- Check network connection
- Verify Nike site is accessible

### No images downloaded
- Check `storage/app/public/images/nike/` directory permissions
- Check Playwright script output in logs
- Verify results.json file exists after script runs

### Images not in database
- Check if images are merged correctly in `parseAndSaveProduct()`
- Verify `downloadAndSaveImage()` is called for Playwright images
- Check database `product_media` table

## Performance Considerations

- **Playwright is slower** than HTTP requests (browser automation)
- Each product/variation takes ~10-30 seconds
- Consider running Playwright only for products with many thumbnails
- Can be disabled by commenting out the `extractHeroImagesWithPlaywright()` calls

## Disabling Playwright

To disable Playwright and use only HTML extraction:

1. Comment out the Playwright calls in `parseAndSaveProduct()`
2. The scraper will continue using `extractImages()` from HTML

```php
// $playwrightImages = $this->extractHeroImagesWithPlaywright($url, $colorUrl);
// if (!empty($playwrightImages)) {
//     $colorData['images'] = array_merge($playwrightImages, $colorData['images']);
// }
```

