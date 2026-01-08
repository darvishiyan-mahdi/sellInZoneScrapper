import { chromium, Browser, Page } from 'playwright';
import * as fs from 'fs';
import * as path from 'path';
import * as https from 'https';
import * as http from 'http';
import { URL } from 'url';

/**
 * Nike Hero Images Scraper
 * 
 * Extracts hero images from Nike product detail pages by clicking through thumbnails.
 * Each thumbnail click updates the hero image, and we capture and download all unique hero images.
 * 
 * Usage:
 *   npx ts-node scripts/nike-hero-images-scraper.ts <productUrl> [variationUrl] [outputDir]
 * 
 * Examples:
 *   npx ts-node scripts/nike-hero-images-scraper.ts "https://www.nike.com/ca/t/product/CODE"
 *   npx ts-node scripts/nike-hero-images-scraper.ts "https://www.nike.com/ca/t/product/CODE" "https://www.nike.com/ca/t/product/CODE-002"
 */

interface HeroImageResult {
    productUrl: string;
    variationUrl: string;
    index: number;
    heroImgSrc: string;
    localPath: string | null;
    thumbnailTestId?: string | null;
    downloadError?: string;
}

// Configuration
const CONFIG = {
    // Timeout for page navigation
    navigationTimeout: 30000,
    // Timeout for waiting hero image to update after thumbnail click
    heroImageUpdateTimeout: 5000,
    // Delay between thumbnail clicks (ms)
    clickDelay: 500,
    // Retry attempts for flaky operations
    maxRetries: 3,
    // Wait time between retries (ms)
    retryDelay: 1000,
    // Headless mode (set to false to see browser)
    headless: true,
    // User agent
    userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    // Default output directory
    defaultOutputDir: path.join(__dirname, '..', 'storage', 'app', 'public', 'images', 'nike'),
};

/**
 * Wait for hero image to update after clicking a thumbnail
 */
async function waitForHeroImageUpdate(
    page: Page,
    previousSrc: string | null,
    timeout: number = CONFIG.heroImageUpdateTimeout
): Promise<string | null> {
    const startTime = Date.now();
    
    while (Date.now() - startTime < timeout) {
        try {
            // Find the visible hero image (aria-hidden="false")
            const visibleHeroImg = page.locator('[data-testid="HeroImg"][aria-hidden="false"]').first();
            
            if (await visibleHeroImg.count() > 0) {
                const currentSrc = await visibleHeroImg.getAttribute('src');
                
                if (currentSrc && currentSrc !== previousSrc && currentSrc !== '') {
                    return currentSrc;
                }
            }
            
            // Fallback: get the last HeroImg in the container if no visible one found
            const allHeroImgs = await page.locator('[data-testid="HeroImg"]').all();
            if (allHeroImgs.length > 0) {
                const lastImg = allHeroImgs[allHeroImgs.length - 1];
                const lastSrc = await lastImg.getAttribute('src');
                
                if (lastSrc && lastSrc !== previousSrc && lastSrc !== '') {
                    return lastSrc;
                }
            }
            
            // Wait a bit before checking again
            await page.waitForTimeout(200);
        } catch (error) {
            // Continue waiting
            await page.waitForTimeout(200);
        }
    }
    
    return null;
}

/**
 * Scroll thumbnail container to bring target thumbnail into view
 */
async function scrollThumbnailIntoView(page: Page, thumbnailTestId: string): Promise<void> {
    try {
        const thumbnailContainer = page.locator('[data-testid="ThumbnailListContainer"]').first();
        
        if (await thumbnailContainer.count() > 0) {
            // Scroll the container to bring thumbnail into view
            const thumbnail = page.locator(`[data-testid="${thumbnailTestId}"]`).first();
            
            if (await thumbnail.count() > 0) {
                await thumbnail.scrollIntoViewIfNeeded();
                // Small delay to ensure scroll completes
                await page.waitForTimeout(300);
            }
        }
    } catch (error) {
        // If scrolling fails, continue anyway - click might still work
        console.warn(`Warning: Could not scroll thumbnail ${thumbnailTestId} into view:`, error);
    }
}

/**
 * Click a thumbnail and wait for hero image to update
 */
async function clickThumbnailAndExtractHeroImage(
    page: Page,
    thumbnailTestId: string,
    previousHeroSrc: string | null
): Promise<string | null> {
    let retries = 0;
    
    while (retries < CONFIG.maxRetries) {
        try {
            // Scroll thumbnail into view first
            await scrollThumbnailIntoView(page, thumbnailTestId);
            
            // Find and click the thumbnail wrapper div
            const thumbnail = page.locator(`[data-testid="${thumbnailTestId}"]`).first();
            
            if (await thumbnail.count() === 0) {
                console.warn(`Thumbnail ${thumbnailTestId} not found`);
                return null;
            }
            
            // Click the thumbnail
            await thumbnail.click({ timeout: 5000 });
            
            // Wait a bit for the click to register
            await page.waitForTimeout(CONFIG.clickDelay);
            
            // Wait for hero image to update
            const newHeroSrc = await waitForHeroImageUpdate(page, previousHeroSrc);
            
            if (newHeroSrc) {
                return newHeroSrc;
            }
            
            // If no update detected, retry
            retries++;
            if (retries < CONFIG.maxRetries) {
                console.log(`Retrying thumbnail click for ${thumbnailTestId} (attempt ${retries + 1}/${CONFIG.maxRetries})`);
                await page.waitForTimeout(CONFIG.retryDelay);
            }
        } catch (error) {
            retries++;
            if (retries < CONFIG.maxRetries) {
                console.warn(`Error clicking thumbnail ${thumbnailTestId}, retrying:`, error);
                await page.waitForTimeout(CONFIG.retryDelay);
            } else {
                console.error(`Failed to click thumbnail ${thumbnailTestId} after ${CONFIG.maxRetries} attempts:`, error);
                return null;
            }
        }
    }
    
    return null;
}

/**
 * Get the current hero image URL without clicking thumbnails
 */
async function getCurrentHeroImage(page: Page): Promise<string | null> {
    try {
        // Try to find visible hero image first
        const visibleHeroImg = page.locator('[data-testid="HeroImg"][aria-hidden="false"]').first();
        
        if (await visibleHeroImg.count() > 0) {
            const src = await visibleHeroImg.getAttribute('src');
            if (src && src !== '') {
                return src;
            }
        }
        
        // Fallback: get the last HeroImg in the container
        const allHeroImgs = await page.locator('[data-testid="HeroImg"]').all();
        if (allHeroImgs.length > 0) {
            const lastImg = allHeroImgs[allHeroImgs.length - 1];
            const src = await lastImg.getAttribute('src');
            if (src && src !== '') {
                return src;
            }
        }
    } catch (error) {
        console.warn('Error getting current hero image:', error);
    }
    
    return null;
}

/**
 * Discover all thumbnail test IDs dynamically
 */
async function discoverThumbnails(page: Page): Promise<string[]> {
    try {
        // Wait for thumbnail container to be present
        await page.waitForSelector('[data-testid^="Thumbnail-"]', { timeout: 10000 }).catch(() => {
            // Thumbnails might not exist, return empty array
        });
        
        // Find all elements with data-testid starting with "Thumbnail-"
        const thumbnailTestIds: string[] = [];
        const thumbnails = await page.locator('[data-testid^="Thumbnail-"]').all();
        
        for (const thumbnail of thumbnails) {
            const testId = await thumbnail.getAttribute('data-testid');
            if (testId && testId.startsWith('Thumbnail-')) {
                thumbnailTestIds.push(testId);
            }
        }
        
        // Sort by index to ensure consistent order
        thumbnailTestIds.sort((a, b) => {
            const indexA = parseInt(a.replace('Thumbnail-', '')) || 0;
            const indexB = parseInt(b.replace('Thumbnail-', '')) || 0;
            return indexA - indexB;
        });
        
        return thumbnailTestIds;
    } catch (error) {
        console.warn('Error discovering thumbnails:', error);
        return [];
    }
}

/**
 * Download an image from URL to local file
 */
async function downloadImage(imageUrl: string, outputPath: string): Promise<string> {
    return new Promise((resolve, reject) => {
        const url = new URL(imageUrl);
        const protocol = url.protocol === 'https:' ? https : http;
        
        const file = fs.createWriteStream(outputPath);
        
        const request = protocol.get(imageUrl, (response) => {
            if (response.statusCode === 301 || response.statusCode === 302) {
                // Handle redirect
                file.close();
                fs.unlinkSync(outputPath);
                return downloadImage(response.headers.location!, outputPath).then(resolve).catch(reject);
            }
            
            if (response.statusCode !== 200) {
                file.close();
                fs.unlinkSync(outputPath);
                return reject(new Error(`Failed to download image: ${response.statusCode}`));
            }
            
            response.pipe(file);
            
            file.on('finish', () => {
                file.close();
                resolve(outputPath);
            });
        });
        
        request.on('error', (err) => {
            file.close();
            if (fs.existsSync(outputPath)) {
                fs.unlinkSync(outputPath);
            }
            reject(err);
        });
        
        request.setTimeout(30000, () => {
            request.destroy();
            file.close();
            if (fs.existsSync(outputPath)) {
                fs.unlinkSync(outputPath);
            }
            reject(new Error('Download timeout'));
        });
    });
}

/**
 * Extract product slug from URL
 */
function extractProductSlug(productUrl: string): string {
    try {
        const url = new URL(productUrl);
        const pathParts = url.pathname.split('/').filter(p => p);
        // Nike URLs: /ca/t/product-name/CODE
        // Return the last part (CODE) or product-name if CODE not available
        return pathParts[pathParts.length - 1] || 'product';
    } catch (error) {
        return 'product';
    }
}

/**
 * Extract variation identifier from URL
 */
function extractVariationId(variationUrl: string | null): string {
    if (!variationUrl) return 'default';
    try {
        const url = new URL(variationUrl);
        const pathParts = url.pathname.split('/').filter(p => p);
        return pathParts[pathParts.length - 1] || 'default';
    } catch (error) {
        return 'default';
    }
}

/**
 * Scrape hero images for a single product URL and download them
 */
async function scrapeProduct(
    page: Page, 
    productUrl: string, 
    variationUrl: string | null = null,
    outputDir: string = CONFIG.defaultOutputDir
): Promise<HeroImageResult[]> {
    const results: HeroImageResult[] = [];
    const urlToScrape = variationUrl || productUrl;
    
    try {
        console.log(`\nScraping product: ${productUrl}`);
        if (variationUrl) {
            console.log(`  Variation URL: ${variationUrl}`);
        }
        console.log(`  Output directory: ${outputDir}`);
        
        // Extract identifiers for folder structure
        const productSlug = extractProductSlug(productUrl);
        const variationId = extractVariationId(variationUrl);
        
        // Create output directory structure: outputDir/productSlug/variationId/
        const productDir = path.join(outputDir, productSlug);
        const variationDir = path.join(productDir, variationId);
        
        // Ensure directories exist
        if (!fs.existsSync(variationDir)) {
            fs.mkdirSync(variationDir, { recursive: true });
        }
        
        // Navigate to product page
        await page.goto(urlToScrape, {
            waitUntil: 'networkidle',
            timeout: CONFIG.navigationTimeout,
        });
        
        // Wait for hero image container to be present
        await page.waitForSelector('[data-testid="HeroImgContainer"], [id="hero-image"]', {
            timeout: 10000,
        });
        
        // Get the initial hero image (index 0)
        const initialHeroSrc = await getCurrentHeroImage(page);
        if (initialHeroSrc) {
            // Download initial image
            const imageExt = path.extname(new URL(initialHeroSrc).pathname) || '.jpg';
            const imageFilename = `hero-0${imageExt}`;
            const imagePath = path.join(variationDir, imageFilename);
            
            try {
                await downloadImage(initialHeroSrc, imagePath);
                results.push({
                    productUrl,
                    variationUrl: variationUrl || productUrl,
                    index: 0,
                    heroImgSrc: initialHeroSrc,
                    localPath: imagePath,
                    thumbnailTestId: null,
                });
                console.log(`  [0] Hero image downloaded: ${imagePath}`);
            } catch (error) {
                console.error(`  [0] Failed to download image: ${error instanceof Error ? error.message : String(error)}`);
                results.push({
                    productUrl,
                    variationUrl: variationUrl || productUrl,
                    index: 0,
                    heroImgSrc: initialHeroSrc,
                    localPath: null,
                    thumbnailTestId: null,
                    downloadError: error instanceof Error ? error.message : String(error),
                });
            }
        }
        
        // Discover all thumbnails
        const thumbnailTestIds = await discoverThumbnails(page);
        
        if (thumbnailTestIds.length === 0) {
            console.log('  No thumbnails found - only initial hero image captured');
            return results;
        }
        
        console.log(`  Found ${thumbnailTestIds.length} thumbnails: ${thumbnailTestIds.join(', ')}`);
        
        // Click through each thumbnail
        let previousHeroSrc = initialHeroSrc;
        
        for (let i = 0; i < thumbnailTestIds.length; i++) {
            const thumbnailTestId = thumbnailTestIds[i];
            const thumbnailIndex = i + 1; // Start from 1 since 0 is the initial image
            
            console.log(`  Clicking thumbnail ${thumbnailIndex}/${thumbnailTestIds.length}: ${thumbnailTestId}`);
            
            const heroImgSrc = await clickThumbnailAndExtractHeroImage(page, thumbnailTestId, previousHeroSrc);
            
            if (heroImgSrc) {
                // Check if this image is already downloaded (duplicate)
                const existingResult = results.find(r => r.heroImgSrc === heroImgSrc);
                if (existingResult) {
                    console.log(`  [${thumbnailIndex}] Duplicate image skipped (same as index ${existingResult.index})`);
                    continue;
                }
                
                // Download the image
                const imageExt = path.extname(new URL(heroImgSrc).pathname) || '.jpg';
                const imageFilename = `hero-${thumbnailIndex}${imageExt}`;
                const imagePath = path.join(variationDir, imageFilename);
                
                try {
                    await downloadImage(heroImgSrc, imagePath);
                    results.push({
                        productUrl,
                        variationUrl: variationUrl || productUrl,
                        index: thumbnailIndex,
                        heroImgSrc,
                        localPath: imagePath,
                        thumbnailTestId,
                    });
                    console.log(`  [${thumbnailIndex}] Hero image downloaded: ${imagePath}`);
                    previousHeroSrc = heroImgSrc;
                } catch (error) {
                    console.error(`  [${thumbnailIndex}] Failed to download image: ${error instanceof Error ? error.message : String(error)}`);
                    results.push({
                        productUrl,
                        variationUrl: variationUrl || productUrl,
                        index: thumbnailIndex,
                        heroImgSrc,
                        localPath: null,
                        thumbnailTestId,
                        downloadError: error instanceof Error ? error.message : String(error),
                    });
                }
            } else {
                console.warn(`  [${thumbnailIndex}] Failed to extract hero image after clicking ${thumbnailTestId}`);
            }
        }
        
        console.log(`  Total unique hero images captured: ${results.length}`);
        console.log(`  Images saved to: ${variationDir}`);
        
    } catch (error) {
        console.error(`Error scraping product ${productUrl}:`, error);
        throw error;
    }
    
    return results;
}

/**
 * Main function to scrape a single product (and optionally a variation)
 */
async function main(): Promise<void> {
    let browser: Browser | null = null;
    
    try {
        // Get command line arguments
        const args = process.argv.slice(2);
        const productUrl = args[0];
        const variationUrl = args[1] || null;
        const outputDir = args[2] || CONFIG.defaultOutputDir;
        
        if (!productUrl) {
            console.error('Usage: npx ts-node nike-hero-images-scraper.ts <productUrl> [variationUrl] [outputDir]');
            console.error('\nExamples:');
            console.error('  npx ts-node nike-hero-images-scraper.ts "https://www.nike.com/ca/t/product/CODE"');
            console.error('  npx ts-node nike-hero-images-scraper.ts "https://www.nike.com/ca/t/product/CODE" "https://www.nike.com/ca/t/product/CODE-002"');
            console.error('  npx ts-node nike-hero-images-scraper.ts "https://www.nike.com/ca/t/product/CODE" "" "./images/nike"');
            process.exit(1);
        }
        
        console.log('Starting Nike Hero Images Scraper');
        console.log(`Product URL: ${productUrl}`);
        if (variationUrl) {
            console.log(`Variation URL: ${variationUrl}`);
        }
        console.log(`Output directory: ${outputDir}`);
        console.log(`Configuration:`, CONFIG);
        
        // Launch browser
        browser = await chromium.launch({
            headless: CONFIG.headless,
        });
        
        const context = await browser.newContext({
            userAgent: CONFIG.userAgent,
            viewport: { width: 1920, height: 1080 },
        });
        
        const page = await context.newPage();
        
        // Scrape the product
        const heroImages = await scrapeProduct(page, productUrl, variationUrl, outputDir);
        
        // Generate output JSON
        const output = {
            timestamp: new Date().toISOString(),
            productUrl,
            variationUrl: variationUrl || productUrl,
            totalImages: heroImages.length,
            images: heroImages,
        };
        
        // Save results JSON
        const productSlug = extractProductSlug(productUrl);
        const variationId = extractVariationId(variationUrl);
        const resultsDir = path.join(outputDir, productSlug, variationId);
        const outputPath = path.join(resultsDir, 'results.json');
        
        fs.writeFileSync(outputPath, JSON.stringify(output, null, 2), 'utf-8');
        
        console.log('\n' + '='.repeat(80));
        console.log('SCRAPING COMPLETE');
        console.log('='.repeat(80));
        console.log(`Total images: ${heroImages.length}`);
        console.log(`Images directory: ${resultsDir}`);
        console.log(`Results JSON: ${outputPath}`);
        console.log('\n' + JSON.stringify(output, null, 2));
        
    } catch (error) {
        console.error('Fatal error:', error);
        process.exit(1);
    } finally {
        if (browser) {
            await browser.close();
        }
    }
}

// Run the scraper
if (require.main === module) {
    main().catch((error) => {
        console.error('Unhandled error:', error);
        process.exit(1);
    });
}

export { 
    scrapeProduct, 
    main, 
    HeroImageResult,
    downloadImage,
    extractProductSlug,
    extractVariationId,
};
