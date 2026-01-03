<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductMedia extends Model {
    use HasFactory;

    protected $fillable = [
        'product_id',
        'type',
        'source_url',
        'local_path',
        'alt_text',
        'is_primary',
    ];

    public function product () : BelongsTo {
        return $this->belongsTo(Product::class);
    }

    protected function casts () : array {
        return [
            'is_primary' => 'boolean',
        ];
    }
}

