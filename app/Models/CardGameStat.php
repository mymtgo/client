<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CardGameStat extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
        'kept' => 'integer',
        'seen' => 'integer',
        'won' => 'boolean',
        'is_postboard' => 'boolean',
        'sided_out' => 'boolean',
        'played' => 'integer',
        'kicked' => 'integer',
        'flashback' => 'integer',
        'madness' => 'integer',
        'evoked' => 'integer',
        'activated' => 'integer',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function deckVersion(): BelongsTo
    {
        return $this->belongsTo(DeckVersion::class);
    }
}
