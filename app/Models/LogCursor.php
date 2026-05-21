<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogCursor extends Model
{
    protected $guarded = [];

    protected $attributes = [
        'byte_offset' => 0,
        'last_observed_size' => 0,
        'stuck_ticks' => 0,
    ];

    protected function casts(): array
    {
        return [
            'byte_offset' => 'integer',
            'last_observed_size' => 'integer',
            'last_advance_at' => 'datetime',
            'stuck_ticks' => 'integer',
            'verify_anchor_offset' => 'integer',
        ];
    }

    public function logInstance(): BelongsTo
    {
        return $this->belongsTo(LogInstance::class);
    }
}
