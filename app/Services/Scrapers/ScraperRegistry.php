<?php

namespace App\Services\Scrapers;

use App\Models\Website;
use App\Services\Products\ProductPersistenceService;
use App\Services\Scrapers\Contracts\ScraperInterface;
use Illuminate\Container\Container;
use RuntimeException;

class ScraperRegistry {
    protected Container                 $container;
    protected ProductPersistenceService $persistenceService;

    public function __construct (Container $container, ProductPersistenceService $persistenceService) {
        $this->container          = $container;
        $this->persistenceService = $persistenceService;
    }

    public function forWebsite (Website $website) : ScraperInterface {
        $scraperClass = $website->getScraperClass();

        if ( !class_exists($scraperClass) ) {
            throw new RuntimeException("Scraper class '{$scraperClass}' not found for website '{$website->slug}'");
        }

        if ( !is_subclass_of($scraperClass, ScraperInterface::class) ) {
            throw new RuntimeException("Scraper class '{$scraperClass}' must implement ScraperInterface");
        }

        return $this->container->make($scraperClass, [
            'website'            => $website,
            'persistenceService' => $this->persistenceService,
        ]);
    }
}

