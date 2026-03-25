<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property GamePlayer|null $pivot
 */
class Player extends Model
{
    protected $fillable = ['username'];

    public function games(): BelongsToMany
    {
        return $this->belongsToMany(Game::class)->withPivot(['player_id']);
    }

    /** @return HasMany<MatchArchetype, $this> */
    public function matchArchetypes(): HasMany
    {
        return $this->hasMany(MatchArchetype::class, 'player_id');
    }
}
