#!/usr/bin/env node

/**
 * Puppeteer script for Lululemon product detail pages
 * 
 * This script clicks on each color swatch to reveal variation data,
 * then outputs the rendered HTML to stdout.
 * 
 * Usage: node scripts/puppeteer-product-detail.js <url> [timeout]
 */

import puppeteer from 'puppeteer';

/**
 * Get a random user agent from a pool of realistic browser user agents.
 */
function getRandomUserAgent() {
    const userAgents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
    ];
    
    return userAgents[Math.floor(Math.random() * userAgents.length)];
}

const url = process.argv[2];
const timeout = parseInt(process.argv[3] || '300000', 10); // Default 5 minutes

if (!url) {
    console.error('Usage: node scripts/puppeteer-product-detail.js <url> [timeout]');
    process.exit(1);
}

(async () => {
    let browser = null;
    try {
        browser = await puppeteer.launch({
            headless: 'new', // Use new headless mode to avoid deprecation warning
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-accelerated-2d-canvas',
                '--disable-gpu',
                '--disable-blink-features=AutomationControlled',
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

        // Get random user agent
        const userAgent = getRandomUserAgent();
        await page.setUserAgent(userAgent);
        
        // Remove webdriver property to avoid detection
        await page.evaluateOnNewDocument(() => {
            Object.defineProperty(navigator, 'webdriver', {
                get: () => false,
            });
            
            // Override plugins
            Object.defineProperty(navigator, 'plugins', {
                get: () => [1, 2, 3, 4, 5],
            });
            
            // Override languages
            Object.defineProperty(navigator, 'languages', {
                get: () => ['en-US', 'en'],
            });
        });

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
            waitUntil: 'domcontentloaded',
            timeout: timeout,
        });

        // Wait for Cloudflare challenge to pass (if present)
        await page.waitForTimeout(3000);
        
        // Check if Cloudflare challenge is present and wait for it to resolve
        const cloudflareCheck = await page.evaluate(() => {
            const indicators = [
                'cf-browser-verification',
                'challenge-platform',
                'cf-error-details',
                'Checking your browser',
                'Just a moment',
            ];
            const bodyText = document.body ? document.body.innerText : '';
            return indicators.some(indicator => bodyText.includes(indicator) || document.querySelector(`[class*="${indicator}"]`));
        });

        if (cloudflareCheck) {
            // Wait for Cloudflare challenge to resolve (up to 30 seconds)
            try {
                await page.waitForFunction(() => {
                    const indicators = [
                        'cf-browser-verification',
                        'challenge-platform',
                        'Checking your browser',
                        'Just a moment',
                    ];
                    const bodyText = document.body ? document.body.innerText : '';
                    const hasIndicator = indicators.some(indicator => 
                        bodyText.includes(indicator) || 
                        document.querySelector(`[class*="${indicator}"]`)
                    );
                    return !hasIndicator;
                }, { timeout: 30000 });
                
                // Additional wait after challenge passes
                await page.waitForTimeout(2000);
            } catch (e) {
                // If challenge doesn't resolve, continue anyway
                console.error('Cloudflare challenge may not have resolved:', e.message);
            }
        }

        // Wait for page to fully load
        await page.waitForTimeout(2000);
        
        // Scroll to trigger lazy loading
        await page.evaluate(() => {
            window.scrollTo(0, document.body.scrollHeight / 2);
        });
        await page.waitForTimeout(1000);

        // Find all color swatch buttons
        const colorButtons = await page.$$('button[data-color-title]');
        
        // FIRST: Extract prices for all color variations from initial page load
        // HTML structure: .color > .cta-price-value > .markdown-prices and .list-price
        const initialColorPrices = await page.evaluate(() => {
            const colorPrices = {};
            
            // Find all color buttons with sale tags
            const colorButtons = document.querySelectorAll('button[data-color-title]');
            
            colorButtons.forEach(button => {
                // Check if this button has a sale tag (in parent .color-group or .color)
                const colorGroup = button.closest('.color-group');
                const colorElement = button.closest('.color');
                
                // Check for sale tag
                let hasSaleTag = false;
                if (colorGroup) {
                    hasSaleTag = colorGroup.querySelector('.sale-tag') !== null;
                }
                if (!hasSaleTag && colorElement) {
                    hasSaleTag = colorElement.querySelector('.sale-tag') !== null;
                }
                
                if (!hasSaleTag) return;
                
                const colorTitle = button.getAttribute('data-color-title');
                if (!colorTitle) return;
                
                // Find price container: .color > .cta-price-value
                let priceContainer = null;
                if (colorElement) {
                    priceContainer = colorElement.querySelector('.cta-price-value');
                }
                if (!priceContainer && colorGroup) {
                    // Fallback: look in color-group or nearby
                    priceContainer = colorGroup.querySelector('.cta-price-value') ||
                                    colorGroup.closest('.color')?.querySelector('.cta-price-value');
                }
                
                if (priceContainer) {
                    let discountPrice = null;
                    let originalPrice = null;
                    
                    // Get discount price from .markdown-prices
                    // <span class="markdown-prices" role="text" aria-label="Total €89">
                    const markdownPrices = priceContainer.querySelector('.markdown-prices');
                    if (markdownPrices) {
                        // Try aria-label first: "Total €89"
                        let discountText = markdownPrices.getAttribute('aria-label');
                        
                        // Try inner span with aria-hidden="true": €89
                        if (!discountText) {
                            const innerSpan = markdownPrices.querySelector('span[aria-hidden="true"]');
                            if (innerSpan) {
                                discountText = innerSpan.textContent.trim();
                            }
                        }
                        
                        // Fallback to textContent
                        if (!discountText) {
                            discountText = markdownPrices.textContent.trim();
                        }
                        
                        if (discountText) {
                            const match = discountText.match(/(?:€|EUR|EUR\s*)?([\d,]+\.?\d*)/);
                            if (match) {
                                discountPrice = parseFloat(match[1].replace(/,/g, ''));
                            }
                        }
                    }
                    
                    // Get original price from .list-price
                    // <span class="list-price">
                    //     <span role="text" aria-label="Original Price was €108">
                    //         <del aria-hidden="true">€108</del>
                    //     </span>
                    // </span>
                    const listPrice = priceContainer.querySelector('.list-price');
                    if (listPrice) {
                        let priceText = null;
                        
                        // Try aria-label from span[role="text"]: "Original Price was €108"
                        const ariaLabelSpan = listPrice.querySelector('span[role="text"][aria-label]');
                        if (ariaLabelSpan) {
                            priceText = ariaLabelSpan.getAttribute('aria-label');
                        }
                        
                        // Try del element: €108
                        if (!priceText) {
                            const delElement = listPrice.querySelector('del');
                            if (delElement) {
                                priceText = delElement.textContent.trim();
                            }
                        }
                        
                        // Fallback to textContent
                        if (!priceText) {
                            priceText = listPrice.textContent.trim();
                        }
                        
                        if (priceText) {
                            const match = priceText.match(/(?:€|EUR|EUR\s*)?([\d,]+\.?\d*)/);
                            if (match) {
                                originalPrice = parseFloat(match[1].replace(/,/g, ''));
                            }
                        }
                    }
                    
                    // Calculate discount percent
                    let discountPercent = null;
                    if (originalPrice && originalPrice > 0 && discountPrice && discountPrice > 0 && discountPrice < originalPrice) {
                        discountPercent = Math.round(100 - (discountPrice / originalPrice) * 100);
                    }
                    
                    // Only store if we have at least discount price (sale variation)
                    if (discountPrice) {
                        colorPrices[colorTitle] = {
                            price: originalPrice,
                            discount_price: discountPrice,
                            discount_percent: discountPercent
                        };
                    }
                }
            });
            
            return colorPrices;
        });
        
        console.error('Extracted initial color prices:', JSON.stringify(initialColorPrices));
        
        // Store color-specific data
        const colorVariationsData = {};
        
        if (colorButtons.length > 0) {
            // Click each color swatch to reveal variation data and capture images
            for (let i = 0; i < colorButtons.length; i++) {
                try {
                    // Check if this color button has a sale tag before processing
                    const hasSaleTag = await page.evaluate((index) => {
                        const buttons = document.querySelectorAll('button[data-color-title]');
                        const button = buttons[index];
                        if (!button) return false;
                        
                        // Check if button or its parent/ancestor has sale-tag class
                        let element = button;
                        while (element) {
                            const classList = element.classList || [];
                            if (classList.contains('sale-tag') || 
                                element.className && element.className.includes('sale-tag')) {
                                return true;
                            }
                            // Check for sale-tag in parent
                            const parent = element.parentElement;
                            if (parent) {
                                const parentClassList = parent.classList || [];
                                if (parentClassList.contains('sale-tag') || 
                                    parent.className && parent.className.includes('sale-tag')) {
                                    return true;
                                }
                            }
                            element = parent;
                            if (!element || element === document.body) break;
                        }
                        
                        // Also check for .sale-tag element inside or near the button
                        const saleTag = button.querySelector('.sale-tag');
                        if (saleTag) return true;
                        
                        // Check parent color-group for sale-tag
                        const colorGroup = button.closest('.color-group');
                        if (colorGroup) {
                            const groupSaleTag = colorGroup.querySelector('.sale-tag');
                            if (groupSaleTag) return true;
                        }
                        
                        return false;
                    }, i);
                    
                    // Skip if no sale tag
                    if (!hasSaleTag) {
                        console.error(`Skipping color ${i} - no sale tag found`);
                        continue;
                    }
                    
                    // Get color title before clicking
                    const colorTitle = await page.evaluate((index) => {
                        const buttons = document.querySelectorAll('button[data-color-title]');
                        return buttons[index] ? buttons[index].getAttribute('data-color-title') : null;
                    }, i);
                    
                    if (!colorTitle) {
                        continue;
                    }
                    
                    // Scroll button into view with smooth behavior
                    await page.evaluate((index) => {
                        const buttons = document.querySelectorAll('button[data-color-title]');
                        if (buttons[index]) {
                            buttons[index].scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    }, i);
                    
                    // Random delay to simulate human behavior
                    await page.waitForTimeout(300 + Math.random() * 200);
                    
                    // Move mouse to button (simulate human interaction)
                    const buttonBox = await colorButtons[i].boundingBox();
                    if (buttonBox) {
                        await page.mouse.move(
                            buttonBox.x + buttonBox.width / 2,
                            buttonBox.y + buttonBox.height / 2,
                            { steps: 10 }
                        );
                        await page.waitForTimeout(100);
                    }
                    
                    // Click the button
                    await colorButtons[i].click({ delay: 50 + Math.random() * 50 });
                    
                    // Wait for content to load (size table, images, etc.)
                    await page.waitForTimeout(1000 + Math.random() * 400);
                    
                    // Wait for any AJAX/network requests
                    try {
                        await page.waitForResponse(
                            response => response.url().includes('Product-Variation') || response.status() === 200,
                            { timeout: 5000 }
                        ).catch(() => {});
                    } catch (e) {
                        // Continue if no specific response
                    }
                    
                    // Wait for prices and thumbnails to update after clicking
                    // Wait for price elements to be visible and updated
                    try {
                        await page.waitForSelector('.markdown-prices, .list-price', { timeout: 3000 });
                    } catch (e) {
                        // Continue if selector not found
                    }
                    
                    // Wait for price to actually update (check if markdown-prices text changes)
                    await page.waitForFunction(() => {
                        const markdownPrices = document.querySelector('.markdown-prices');
                        if (!markdownPrices) return false;
                        const text = markdownPrices.textContent.trim();
                        return text.length > 0 && text.includes('€');
                    }, { timeout: 3000 }).catch(() => {});
                    
                    // Additional wait to ensure prices are fully updated
                    await page.waitForTimeout(800);
                    
                    // Check again for sale tag AFTER clicking (to ensure this color is actually on sale)
                    const hasSaleTagAfterClick = await page.evaluate(() => {
                        // Check if there's a sale-tag visible in the price area
                        const saleTag = document.querySelector('.sale-tag');
                        if (!saleTag) return false;
                        
                        // Also check if markdown-prices exists (indicates sale)
                        const markdownPrices = document.querySelector('.markdown-prices');
                        const listPriceDel = document.querySelector('.list-price del');
                        
                        // Must have both markdown price and original price (del) to be a sale
                        return markdownPrices && listPriceDel;
                    });
                    
                    // Skip if no sale tag after clicking (this is the default/opening color)
                    if (!hasSaleTagAfterClick) {
                        console.error(`Skipping color ${colorTitle} - no sale tag found after clicking (default color)`);
                        continue;
                    }
                    
                    // Extract full variation data for this color: images, sizes
                    // Use prices from initial page load (initialColorPrices) instead of extracting after click
                    const colorVariationData = await page.evaluate(() => {
                        const data = {
                            images: [],
                            sizes: { available: [], unavailable: [] }
                        };
                        
                        // Extract images from thumbnails
                        const thumbnails = document.querySelector('#thumbnails');
                        if (thumbnails) {
                            const links = thumbnails.querySelectorAll('a[href]');
                            links.forEach(link => {
                                const href = link.getAttribute('href');
                                if (href && href.includes('lululemon.com/is/image')) {
                                    // Normalize to 1600x1600
                                    let normalizedUrl = href;
                                    if (normalizedUrl.includes('?size=')) {
                                        normalizedUrl = normalizedUrl.replace(/[?&]size=[^&]*/, '?size=1600,1600');
                                    } else {
                                        normalizedUrl += (normalizedUrl.includes('?') ? '&' : '?') + 'size=1600,1600';
                                    }
                                    
                                    // Get alt text from img inside
                                    const img = link.querySelector('img');
                                    const alt = img ? (img.getAttribute('alt') || '') : '';
                                    
                                    data.images.push({
                                        url: normalizedUrl,
                                        alt: alt
                                    });
                                }
                            });
                        }
                        
                        // Extract sizes from input.options-select elements
                        const sizeInputs = document.querySelectorAll('input.options-select');
                        sizeInputs.forEach(input => {
                            const size = input.getAttribute('data-attr-value') || 
                                       input.getAttribute('data-attr-hybridsize') ||
                                       input.getAttribute('id') ||
                                       input.getAttribute('value');
                            
                            if (size) {
                                const classAttr = input.getAttribute('class') || '';
                                const hasDisabledClass = classAttr.includes('disabled');
                                const isDisabled = hasDisabledClass || 
                                                 input.hasAttribute('disabled') || 
                                                 input.getAttribute('aria-disabled') === 'true' ||
                                                 (input.getAttribute('aria-label') || '').toLowerCase().includes('sold out');
                                
                                if (isDisabled) {
                                    data.sizes.unavailable.push(size);
                                } else {
                                    data.sizes.available.push(size);
                                }
                            }
                        });
                        
                        return data;
                    });
                    
                    // Use prices from initial page load for this color
                    const colorPrices = initialColorPrices[colorTitle] || {};
                    colorVariationData.price = colorPrices.price || null;
                    colorVariationData.discount_price = colorPrices.discount_price || null;
                    colorVariationData.discount_percent = colorPrices.discount_percent || null;
                    
                    // Debug: Log raw price elements to help diagnose (after extraction)
                    const priceDebug = await page.evaluate(() => {
                        const debug = {
                            listPriceDel: null,
                            markdownPrices: null,
                            listPriceDelText: null,
                            markdownPricesText: null,
                            listPriceDelAria: null,
                            markdownPricesAria: null,
                            listPriceDelInnerHTML: null,
                            markdownPricesInnerHTML: null,
                            ctaPriceValue: null
                        };
                        
                        // Get the entire price container
                        const ctaPriceValue = document.querySelector('.cta-price-value');
                        if (ctaPriceValue) {
                            debug.ctaPriceValue = ctaPriceValue.outerHTML;
                        }
                        
                        const listPriceDel = document.querySelector('.list-price del');
                        if (listPriceDel) {
                            debug.listPriceDel = listPriceDel.outerHTML.substring(0, 200);
                            debug.listPriceDelText = listPriceDel.textContent.trim();
                            debug.listPriceDelInnerHTML = listPriceDel.innerHTML.trim();
                            const parent = listPriceDel.closest('.list-price');
                            if (parent) {
                                debug.listPriceDelAria = parent.getAttribute('aria-label') || parent.querySelector('[aria-label]')?.getAttribute('aria-label');
                            }
                        }
                        
                        const markdownPrices = document.querySelector('.markdown-prices');
                        if (markdownPrices) {
                            debug.markdownPrices = markdownPrices.outerHTML.substring(0, 200);
                            debug.markdownPricesText = markdownPrices.textContent.trim();
                            debug.markdownPricesInnerHTML = markdownPrices.innerHTML.trim();
                            debug.markdownPricesAria = markdownPrices.getAttribute('aria-label');
                            
                            // Also check inner span
                            const innerSpan = markdownPrices.querySelector('span[aria-hidden="true"]');
                            if (innerSpan) {
                                debug.markdownPricesInnerSpanText = innerSpan.textContent.trim();
                                debug.markdownPricesInnerSpanInnerHTML = innerSpan.innerHTML.trim();
                            }
                        }
                        
                        return debug;
                    });
                    
                    // Store color-specific variation data
                    colorVariationsData[colorTitle] = colorVariationData;
                    
                    // Output price debug in a structured format that PHP can parse (after storing data)
                    console.error('LULULEMON_PRICE_DEBUG_START');
                    console.error(JSON.stringify({
                        color: colorTitle,
                        debug: priceDebug,
                        extracted: {
                            price: colorVariationData.price,
                            discount_price: colorVariationData.discount_price,
                            discount_percent: colorVariationData.discount_percent
                        }
                    }));
                    console.error('LULULEMON_PRICE_DEBUG_END');
                    console.error(`Captured variation data for color: ${colorTitle}`, {
                        images: colorVariationData.images.length,
                        available_sizes: colorVariationData.sizes.available.length,
                        unavailable_sizes: colorVariationData.sizes.unavailable.length,
                        price: colorVariationData.price,
                        discount_price: colorVariationData.discount_price,
                        discount_percent: colorVariationData.discount_percent
                    });
                    
                    // Wait for DOM updates
                    await page.waitForTimeout(500);
                } catch (error) {
                    // Continue with next button if this one fails
                    console.error(`Error clicking color button ${i}:`, error.message);
                }
            }
            
            // Final wait to ensure all variations are loaded
            await page.waitForTimeout(2000);
        }
        
        // Inject color variations data into the page as a script tag before getting HTML
        await page.evaluate((data) => {
            // Remove existing script if any
            const existing = document.getElementById('lululemon-color-variations-data');
            if (existing) {
                existing.remove();
            }
            
            // Create new script with color variations data
            const script = document.createElement('script');
            script.id = 'lululemon-color-variations-data';
            script.type = 'text/javascript';
            // Store as both data attribute and in script content for maximum compatibility
            const jsonData = JSON.stringify(data);
            script.setAttribute('data-variations', jsonData);
            script.textContent = `window.__LULULEMON_COLOR_VARIATIONS__ = ${jsonData};`;
            document.head.appendChild(script);
        }, colorVariationsData);
        
        // Also output color variations data to stderr for PHP to parse (as backup)
        console.error('LULULEMON_COLOR_VARIATIONS_START');
        console.error(JSON.stringify(colorVariationsData));
        console.error('LULULEMON_COLOR_VARIATIONS_END');

        // Scroll to ensure all images are loaded
        await page.evaluate(() => {
            window.scrollTo(0, document.body.scrollHeight);
        });
        await page.waitForTimeout(1000);
        
        await page.evaluate(() => {
            window.scrollTo(0, 0);
        });
        await page.waitForTimeout(500);

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

