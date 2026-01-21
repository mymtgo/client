<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GameDeck extends Model
{
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function cards(): HasMany
    {
        return $this->hasMany(GameDeckCard::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
