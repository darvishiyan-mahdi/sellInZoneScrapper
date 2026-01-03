#!/usr/bin/env node

/**
 * Puppeteer render script for Laravel
 * 
 * This script accepts a URL from command line and renders it using Puppeteer,
 * then outputs the rendered HTML to stdout.
 * 
 * Usage: node scripts/puppeteer-render.js <url> [waitSelector] [timeout]
 */

import puppeteer from 'puppeteer';

/**
 * Get a random user agent from a pool of realistic browser user agents.
 */
function getRandomUserAgent() {
    const userAgents = [
        // Chrome on Windows
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        
        // Chrome on macOS
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
        
        // Firefox on Windows
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:132.0) Gecko/20100101 Firefox/132.0',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:131.0) Gecko/20100101 Firefox/131.0',
        
        // Safari on macOS
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2.1 Safari/605.1.15',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1.2 Safari/605.1.15',
        
        // Edge on Windows
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0',
    ];
    
    return userAgents[Math.floor(Math.random() * userAgents.length)];
}

const url = process.argv[2];
const waitSelector = process.argv[3] || null;
const timeout = parseInt(process.argv[4] || '60000', 10);

if (!url) {
    console.error('Usage: node scripts/puppeteer-render.js <url> [waitSelector] [timeout]');
    process.exit(1);
}

(async () => {
    let browser = null;
    try {
        browser = await puppeteer.launch({
            headless: true,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-accelerated-2d-canvas',
                '--disable-gpu',
                '--disable-web-security',
                '--disable-features=IsolateOrigins,site-per-process',
            ],
        });

        const page = await browser.newPage();

        // Set realistic viewport
        await page.setViewport({
            width: 1920,
            height: 1080,
            deviceScaleFactor: 1,
        });

        // Get random user agent from pool
        const userAgent = getRandomUserAgent();
        await page.setUserAgent(userAgent);

        await page.setExtraHTTPHeaders({
            'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
            'Accept-Language': 'en-US,en;q=0.9',
            'Accept-Encoding': 'gzip, deflate, br',
            'Connection': 'keep-alive',
            'Upgrade-Insecure-Requests': '1',
            'Sec-Fetch-Dest': 'document',
            'Sec-Fetch-Mode': 'navigate',
            'Sec-Fetch-Site': 'none',
            'Sec-Fetch-User': '?1',
            'Cache-Control': 'max-age=0',
        });

        // Navigate to the page
        await page.goto(url, {
            waitUntil: 'networkidle2',
            timeout: timeout,
        });

        // Scroll the page to trigger lazy loading and JavaScript execution
        // This simulates a real user scrolling and helps trigger JavaScript that populates content
        await page.evaluate(() => {
            return new Promise((resolve) => {
                let totalHeight = 0;
                const distance = 200;
                const delay = 150; // More realistic scroll delay
                
                const timer = setInterval(() => {
                    const scrollHeight = document.body.scrollHeight;
                    window.scrollBy(0, distance);
                    totalHeight += distance;

                    // Scroll to bottom in increments, then scroll back to top
                    if (totalHeight >= scrollHeight) {
                        clearInterval(timer);
                        // Small pause at bottom to let content load
                        setTimeout(() => {
                            // Scroll back to top gradually
                            window.scrollTo({
                                top: 0,
                                behavior: 'smooth'
                            });
                            setTimeout(resolve, 800);
                        }, 800);
                    }
                }, delay);
            });
        });
        
        // Wait a bit after initial scrolling to let JavaScript execute
        await page.waitForTimeout(2000);

        // If no waitSelector provided (fetching full page with all products), do aggressive scrolling
        // to ensure all lazy-loaded products are loaded
        if (!waitSelector) {
            // Scroll to bottom multiple times to trigger lazy loading
            // Each scroll may reveal more content, so we scroll until no more content loads
            let previousHeight = 0;
            let currentHeight = await page.evaluate(() => document.body.scrollHeight);
            let attempts = 0;
            const maxAttempts = 10;
            
            while (currentHeight > previousHeight && attempts < maxAttempts) {
                // Scroll to bottom
                await page.evaluate(() => {
                    window.scrollTo(0, document.body.scrollHeight);
                });
                
                // Wait for content to load
                await page.waitForTimeout(2000);
                
                // Check if page height increased (more content loaded)
                previousHeight = currentHeight;
                currentHeight = await page.evaluate(() => document.body.scrollHeight);
                attempts++;
            }
            
            // Scroll back to top and then to bottom one more time
            await page.evaluate(() => {
                window.scrollTo(0, 0);
            });
            await page.waitForTimeout(1000);
            
            await page.evaluate(() => {
                window.scrollTo(0, document.body.scrollHeight);
            });
            await page.waitForTimeout(3000);
            
            // Final wait to ensure all products are fully rendered
            await page.waitForTimeout(2000);
        }

        // Wait for specific selector if provided, and ensure it has content
        if (waitSelector) {
            try {
                // First wait for the element to exist
                await page.waitForSelector(waitSelector, {
                    timeout: timeout,
                    visible: false, // element might be sr-only (screen reader only)
                });

                // Then wait for it to have actual text content (not empty)
                // Retry checking for content with a reasonable timeout
                const contentCheckTimeout = Math.min(timeout, 30000); // Max 30 seconds for content check
                let contentFound = false;
                const startTime = Date.now();
                
                while (!contentFound && (Date.now() - startTime) < contentCheckTimeout) {
                    const hasContent = await page.evaluate((selector) => {
                        const element = document.querySelector(selector);
                        return element && element.textContent && element.textContent.trim().length > 0;
                    }, waitSelector);
                    
                    if (hasContent) {
                        contentFound = true;
                        break;
                    }
                    
                    // Wait a bit before checking again
                    await page.waitForTimeout(500);
                }
                
                if (!contentFound) {
                    console.error(`Warning: Element "${waitSelector}" found but remains empty after ${contentCheckTimeout}ms`);
                }

                // Additional wait to ensure all JavaScript has finished populating
                await page.waitForTimeout(3000);
            } catch (error) {
                // If selector or content not found, continue anyway but log to stderr
                console.error(`Warning: Selector "${waitSelector}" not found or empty within timeout`);
            }
        } else {
            // Even if no selector provided, wait a bit for dynamic content
            await page.waitForTimeout(3000);
        }

        // Get the rendered HTML
        const html = await page.content();

        // Output HTML to stdout
        console.log(html);

        await browser.close();
        process.exit(0);
    } catch (error) {
        console.error('Error rendering page:', error.message);
        if (browser) {
            await browser.close();
        }
        process.exit(1);
    }
})();

