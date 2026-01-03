<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Website extends Model {
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'base_url',
        'slug',
        'is_active',
        'scraper_class',
        'extra_config',
    ];

    public function products () : HasMany {
        return $this->hasMany(Product::class);
    }

    public function scrapeJobs () : HasMany {
        return $this->hasMany(ScrapeJob::class);
    }

    public function getScraperClass () : string {
        if ( $this->scraper_class ) {
            return $this->scraper_class;
        }

        $className = 'App\\Services\\Scrapers\\' . Str::studly($this->slug) . 'Scraper';

        return $className;
    }

    protected function casts () : array {
        return [
            'is_active'    => 'boolean',
            'extra_config' => 'array',
        ];
    }
}

