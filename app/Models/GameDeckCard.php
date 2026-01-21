<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameDeckCard extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
        'sideboard' => 'boolean',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }
}
