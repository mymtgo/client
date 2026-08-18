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
        'warp' => 'integer',
        'free_cast' => 'integer',
        'bargained' => 'integer',
        'dashed' => 'integer',
        'bestowed' => 'integer',
        'replicated' => 'integer',
        'spectacle' => 'integer',
        'rebound' => 'integer',
        'escaped' => 'integer',
        'ninjutsu' => 'integer',
        'suspended' => 'integer',
        'buyback' => 'integer',
        'disturb' => 'integer',
        'foretold' => 'integer',
        'retraced' => 'integer',
        'mayhem' => 'integer',
        'miracle' => 'integer',
        'gifted' => 'integer',
        'casualty' => 'integer',
        'activated' => 'integer',
        'pregame_revealed' => 'boolean',
        'pregame_played' => 'boolean',
        'opponent' => 'boolean',
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
