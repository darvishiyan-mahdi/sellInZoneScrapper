<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductWordpressMapping extends Model {
    use HasFactory;

    protected $fillable = [
        'product_id',
        'wordpress_product_id',
        'wordpress_site_url',
        'last_synced_at',
        'last_sync_status',
        'last_sync_payload',
    ];

    public function product () : BelongsTo {
        return $this->belongsTo(Product::class);
    }

    protected function casts () : array {
        return [
            'last_synced_at'    => 'datetime',
            'last_sync_payload' => 'array',
        ];
    }
}

