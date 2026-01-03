<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model {
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'website_id',
        'external_id',
        'title',
        'slug',
        'description',
        'price',
        'currency',
        'stock_quantity',
        'status',
        'raw_data',
        'meta',
    ];

    public function website () : BelongsTo {
        return $this->belongsTo(Website::class);
    }

    public function media () : HasMany {
        return $this->hasMany(ProductMedia::class);
    }

    public function attributes () : HasMany {
        return $this->hasMany(ProductAttribute::class);
    }

    public function wordpressMapping () : HasOne {
        return $this->hasOne(ProductWordpressMapping::class);
    }

    protected function casts () : array {
        return [
            'price'          => 'decimal:2',
            'stock_quantity' => 'integer',
            'raw_data'       => 'array',
            'meta'           => 'array',
        ];
    }
}

