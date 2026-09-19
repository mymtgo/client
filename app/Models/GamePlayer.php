<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class GamePlayer extends Pivot
{
    protected $casts = [
        'is_local' => 'bool',
        'on_play' => 'bool',
        'deck_json' => 'array',
        'opening_hand_json' => 'array',
        'mulligan_count' => 'integer',
    ];

    // Sync dirtiness: editing this row must bump the parent's updated_at.
    protected $touches = ['game'];

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
