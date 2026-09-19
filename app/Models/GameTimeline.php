<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameTimeline extends Model
{
    protected $guarded = [];

    protected $casts = [
        'content' => 'array',
    ];

    // Sync dirtiness: editing this row must bump the parent's updated_at.
    protected $touches = ['game'];

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
