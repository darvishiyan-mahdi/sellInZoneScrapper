<?php

namespace App\Services\Scrapers\Contracts;

use App\Models\ScrapeJob;

interface ScraperInterface {
    public function run (ScrapeJob $job) : void;

    public function fetchProducts () : iterable;

    public function normalizeProduct (array $rawItem) : array;
}

