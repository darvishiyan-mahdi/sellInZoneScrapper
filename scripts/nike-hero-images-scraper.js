import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { dirname } from 'path';
import https from 'https';
import http from 'http';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const logsDir = path.join(__dirname, '..', 'storage', 'logs');

function log(message) {
    const timestamp = new Date().toISOString();
    console.log(`[${timestamp}] ${message}`);
}

function extractSlug(url) {
    try {
        const urlObj = new URL(url);
        const parts = urlObj.pathname.split('/').filter(p => p);
        return parts[parts.length - 1] || 'product';
    } catch {
        return 'product';
    }
}

function downloadImage(imageUrl, outputPath) {
    return new Promise((resolve, reject) => {
        const urlObj = new URL(imageUrl);
        const client = urlObj.protocol === 'https:' ? https : http;
        
        const options = {
            headers: {
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36',
                'Accept': 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                'Accept-Language': 'en-US,en;q=0.9',
                'Referer': 'https://www.nike.com/'
            }
        };
        
        const file = fs.createWriteStream(outputPath);
        
        const request = client.get(imageUrl, options, (response) => {
            if (response.statusCode === 200) {
                response.pipe(file);
                file.on('finish', () => {
                    file.close();
                    resolve(outputPath);
                });
            } else if (response.statusCode === 301 || response.statusCode === 302 || response.statusCode === 307 || response.statusCode === 308) {
                // Handle redirects
                file.close();
                if (fs.existsSync(outputPath)) {
                    fs.unlinkSync(outputPath);
                }
                const redirectUrl = response.headers.location;
                const absoluteUrl = redirectUrl.startsWith('http') ? redirectUrl : new URL(redirectUrl, imageUrl).href;
                downloadImage(absoluteUrl, outputPath).then(resolve).catch(reject);
            } else {
                file.close();
                if (fs.existsSync(outputPath)) {
                    fs.unlinkSync(outputPath);
                }
                reject(new Error(`Failed to download image: HTTP ${response.statusCode}`));
            }
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

async function main() {
    const productUrl = process.argv[2];
    const variationUrl = process.argv[3] || '';
    const outputDir = process.argv[4] || path.join(__dirname, '..', 'storage', 'app', 'public', 'images', 'nike');
    
    if (!productUrl) {
        console.error('Usage: node scripts/nike-hero-images-scraper.js <productUrl> [variationUrl] [outputDir]');
        process.exit(1);
    }
    
    // Extract slugs
    const productSlug = extractSlug(productUrl);
    const variationId = variationUrl ? extractSlug(variationUrl) : 'default';
    const urlToScrape = variationUrl || productUrl;
    
    log(`Starting scraper for product: ${productSlug}, variation: ${variationId}`);
    log(`Product URL: ${productUrl}`);
    if (variationUrl) {
        log(`Variation URL: ${variationUrl}`);
    }
    log(`Output directory: ${outputDir}`);
    
    // Create output directory structure
    const productOutputDir = path.join(outputDir, productSlug, variationId);
    if (!fs.existsSync(productOutputDir)) {
        log(`Creating output directory: ${productOutputDir}`);
        fs.mkdirSync(productOutputDir, { recursive: true });
    }
    
    // Ensure logs directory exists
    if (!fs.existsSync(logsDir)) {
        log(`Creating logs directory: ${logsDir}`);
        fs.mkdirSync(logsDir, { recursive: true });
    }
    
    let browser = null;
    
    try {
        log('Launching browser...');
        browser = await chromium.launch({ 
            headless: true
        });
        log('Browser launched successfully');
        
        log('Creating browser context with headers...');
        const context = await browser.newContext({
            userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36',
            extraHTTPHeaders: {
                'accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
                'accept-encoding': 'gzip, deflate, br, zstd',
                'accept-language': 'en-US,en;q=0.9,fa;q=0.8',
                'cache-control': 'max-age=0',
                'cookie': 'geoloc=cc=NL,rc=,tp=vhigh,tz=GMT+1,la=52.35,lo=4.92; ni_d=2BB9F2EA-3EDD-414A-a450-A3BE4E4B3BDE; ni_c=1PA=1|BEAD=1|PERF=1|PERS=1; anonymousId=F374A3336E7FBB530461729F39D3A4E1; _fbp=fb.1.1767635290354.425698796418; _rdt_uuid=1767635290356.cdbe650b-f351-459a-903a-7cfae668e983; _gcl_au=1.1.109954080.1767635291; _ga=GA1.1.1877446981.1767635292; FPID=FPID2.2.6SmluqU%2Bi4aHw145JiPnGMZ5sXma359O4anCICKu5tE%3D.1767635292; FPAU=1.1.109954080.1767635291; _tt_enable_cookie=1; _ttp=01KE7MC4RJKH14DBBQM83Q17S5_.tt.1; _scid=7ky10pqRhCx4drdTHHlm3_hRcVUmFewu; _ScCbts=%5B%5D; NIKE_COMMERCE_COUNTRY=CA; NIKE_COMMERCE_LANG_LOCALE=en_GB; nike_locale=ca/en_gb; CONSUMERCHOICE=ca/en_gb; CONSUMERCHOICE_SESSION=t; _pin_unauth=dWlkPVpHWTNaV0V3WkRndE1EVXdZUzAwTTJNekxXSmlZVEV0TlRVME1UYzNPVGxqTXpkaQ; EU_COOKIE_LAW_CONSENT=true; EU_COOKIE_LAW_CONSENT_legacy=true; styliticsWidgetSession=db8bf570-a871-4c7b-8a4b-6381322ecf38; FPLC=dxwV%2BLIPEXPWRS3314ov1mbbapZujG4rJGAVO1YlMjEoSKpumFCZiKV6U8gAB5wYiKfiDQza8Gi8WoB5OSbHAfkYqvGCKiMD%2BFVJwrQgKv38Bc9fnKhM3KVLBKsMBA%3D%3D; _clck=1cy1ogf%5E2%5Eg2h%5E1%5E2196; KP_UIDz-ssn=03euh1q8SAKpAswmdgO41HZM0u2pz1qwhOjACCckXgVvS4wmorQJJHL3xVhY6A79h0zX81H9PUXAXBcmSvuFLPRzyNBf55n1UMsLlwEpMkYtmkkYOV8Feea79wAC3S7qaLx21d26k3eOAKsz8i0V9oACFxCPsWpvDQ6uUDtmK51Q2q; KP_UIDz=03euh1q8SAKpAswmdgO41HZM0u2pz1qwhOjACCckXgVvS4wmorQJJHL3xVhY6A79h0zX81H9PUXAXBcmSvuFLPRzyNBf55n1UMsLlwEpMkYtmkkYOV8Feea79wAC3S7qaLx21d26k3eOAKsz8i0V9oACFxCPsWpvDQ6uUDtmK51Q2q; ni_cs=680de70d-f01b-4b93-8051-5d980b36d6f1; kndctr_F0935E09512D2C270A490D4D_AdobeOrg_cluster=irl1; kndctr_F0935E09512D2C270A490D4D_AdobeOrg_identity=CiYyMzQ5NjU1NTM1ODQ1MDAxMDY1MjY4NzMyMDEzOTcxODA1NTcxNVITCI6TmPq4MxABGAEqBElSTDEwAPABmOeYpLkz; TTSVID=82430f29-26b2-458f-a91c-5ba210b7ef1d; pixlee_analytics_cookie_legacy=%7B%22CURRENT_PIXLEE_USER_ID%22%3A%221aceada9-bea4-e435-790f-465781bad487%22%2C%22TIME_SPENT%22%3A13%2C%22BOUNCED%22%3Afalse%7D; ni_pp=pdp|nikecom>pdp>nike%20shox%20r4; AKA_A2=A; cto_bundle=pSSpH18wMnF3TmJaYUt4T0xtQmRPUXlKJTJCY1ZyZGJEN2RnVTJ5UG93TnJLNDVuaHVxc3RMYjMyUVI5ZmlZbmRWVERUb3NwRTlNTzE4JTJCb2hBbWxNYXhSJTJGMHhmMUF1MkFnSHMwcm56SFd5SXJvaTd4a2lOeHRRS2wwdHBTbEEwNG1pT1N1eGJ0NnZpeERwU25uRkpsSHMlMkJjcGYlMkZBJTNEJTNE; _scid_r=_cy10pqRhCx4drdTHHlm3_hRcVUmFewu12FzIw; _uetsid=b4859020ea5e11f08dc0b118aa964777; _uetvid=b485c1f0ea5e11f09ba46946577481de; FPGSID=1.1767727708.1767727708.G-QTVTHYLBQS.QE5zxrmLGjAyXuvru3kBUA; ttcsid=1767723368781::fvxn10Y07nxPzkt3F6wk.6.1767727830048.0; ttcsid_CK0T0HRC77UDO397LEB0=1767723368782::XGeNrl7KiiIhHf5wKk6w.6.1767727830049.1; _ga_QTVTHYLBQS=GS2.1.s1767723370$o7$g1$t1767728587$j60$l0$h0; RT="z=1&dm=www.nike.com&si=bbe0a3bd-1be7-41f8-a061-2a2204f9048f&ss=mk2zff9l&sl=0&tt=0&bcn=%2F%2F684dd329.akstat.io%2F"; _clsk=1mrt5kq%5E1767729104826%5E9%5E0%5Ei.clarity.ms%2Fcollect',
                'if-none-match': '"4bf00-6ucVAXKqaabETZuAlkdlPN+tH5A"',
                'priority': 'u=0, i',
                'sec-ch-ua': '"Google Chrome";v="143", "Chromium";v="143", "Not A(Brand";v="24"',
                'sec-ch-ua-full-version-list': '"Google Chrome";v="143.0.7499.170", "Chromium";v="143.0.7499.170", "Not A(Brand";v="24.0.0.0"',
                'sec-ch-ua-mobile': '?0',
                'sec-ch-ua-model': '""',
                'sec-ch-ua-platform': '"Windows"',
                'sec-ch-ua-platform-version': '"19.0.0"',
                'sec-fetch-dest': 'document',
                'sec-fetch-mode': 'navigate',
                'sec-fetch-site': 'same-origin',
                'sec-fetch-user': '?1',
                'upgrade-insecure-requests': '1'
            }
        });
        log('Browser context created with headers and user agent');
        
        log('Creating new page...');
        const page = await context.newPage();
        log('Page created');
        
        log(`Navigating to: ${urlToScrape}`);
        await page.goto(urlToScrape, { 
            waitUntil: 'networkidle',
            timeout: 60000
        });
        log('Page loaded successfully');
        
        // Find and count thumbnails
        log('Finding thumbnails...');
        const thumbnailCount = await page.evaluate(() => {
            const container = document.querySelector('[data-testid="ThumbnailListContainer"]');
            if (!container) return 0;
            const thumbnails = container.querySelectorAll('[data-testid^="Thumbnail-"]');
            return thumbnails.length;
        });
        log(`Found ${thumbnailCount} thumbnails`);
        
        // Click all thumbnails with 1 second delay between each
        if (thumbnailCount > 0) {
            log('Clicking all thumbnails...');
            for (let i = 0; i < thumbnailCount; i++) {
                log(`Clicking thumbnail ${i}...`);
                await page.evaluate((index) => {
                    const thumbnail = document.querySelector(`[data-testid="Thumbnail-${index}"]`);
                    if (thumbnail) {
                        thumbnail.click();
                    }
                }, i);
                
                // Wait 1 second before next click (except for the last one)
                if (i < thumbnailCount - 1) {
                    await page.waitForTimeout(1000);
                }
            }
            log(`All ${thumbnailCount} thumbnails clicked`);
        } else {
            log('No thumbnails found to click');
        }
        
        // Wait a bit for all images to load
        await page.waitForTimeout(2000);
        
        // Extract all image URLs from HeroImgContainer
        log('Extracting image URLs from HeroImgContainer...');
        const imageUrls = await page.evaluate(() => {
            const container = document.querySelector('[data-testid="HeroImgContainer"]');
            if (!container) return [];
            
            const images = [];
            const imgElements = container.querySelectorAll('img[data-testid="HeroImg"]');
            
            imgElements.forEach((img, index) => {
                const src = img.getAttribute('src');
                if (src && src.trim()) {
                    images.push({
                        url: src,
                        alt: img.getAttribute('alt') || '',
                        index: index
                    });
                }
            });
            
            return images;
        });
        
        log(`Found ${imageUrls.length} hero images`);
        
        // Download all images
        const results = [];
        for (let i = 0; i < imageUrls.length; i++) {
            const imageData = imageUrls[i];
            const imageUrl = imageData.url;
            
            try {
                log(`Downloading image ${i + 1}/${imageUrls.length}: ${imageUrl}`);
                
                // Get file extension from URL
                const urlPath = new URL(imageUrl).pathname;
                const extension = path.extname(urlPath) || '.jpg';
                const filename = `image_${i}_${Date.now()}${extension}`;
                const localPath = path.join(productOutputDir, filename);
                
                await downloadImage(imageUrl, localPath);
                
                const relativePath = path.join(productSlug, variationId, filename);
                results.push({
                    heroImgSrc: imageUrl,
                    localPath: relativePath,
                    index: i,
                    alt: imageData.alt
                });
                
                log(`Image ${i + 1} downloaded successfully: ${localPath}`);
            } catch (error) {
                log(`Failed to download image ${i + 1}: ${error.message}`);
                // Still add the URL even if download failed
                results.push({
                    heroImgSrc: imageUrl,
                    localPath: null,
                    index: i,
                    alt: imageData.alt,
                    error: error.message
                });
            }
        }
        
        // Save results.json
        const resultsPath = path.join(productOutputDir, 'results.json');
        const resultsData = {
            productUrl: productUrl,
            variationUrl: variationUrl || null,
            productSlug: productSlug,
            variationId: variationId,
            images: results,
            timestamp: new Date().toISOString()
        };
        
        log(`Saving results to: ${resultsPath}`);
        fs.writeFileSync(resultsPath, JSON.stringify(resultsData, null, 2), 'utf-8');
        log(`Results saved successfully. Total images: ${results.length}`);
        
    } catch (error) {
        log(`ERROR: ${error.message}`);
        console.error('Error details:', error);
        process.exit(1);
    } finally {
        if (browser) {
            log('Closing browser...');
            await browser.close();
            log('Browser closed');
        }
    }
    
    log('Scraper completed successfully');
}

main().catch(error => {
    log(`FATAL ERROR: ${error.message}`);
    console.error(error);
    process.exit(1);
});
