<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchArchetype extends Model
{
    protected $guarded = [];

    public function match(): BelongsTo
    {
        return $this->belongsTo(MtgoMatch::class, 'mtgo_match_id');
    }

    public function archetype(): BelongsTo
    {
        return $this->belongsTo(Archetype::class, 'archetype_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id');
    }
}
