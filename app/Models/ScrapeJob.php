<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrapeJob extends Model {
    use HasFactory;

    protected $fillable = [
        'website_id',
        'status',
        'started_at',
        'finished_at',
        'total_found',
        'total_created',
        'total_updated',
        'error_message',
    ];

    public function website () : BelongsTo {
        return $this->belongsTo(Website::class);
    }

    protected function casts () : array {
        return [
            'started_at'    => 'datetime',
            'finished_at'   => 'datetime',
            'total_found'   => 'integer',
            'total_created' => 'integer',
            'total_updated' => 'integer',
        ];
    }
}

