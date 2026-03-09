<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Player extends Model
{
    protected $fillable = ['username'];

    public function games(): BelongsToMany
    {
        return $this->belongsToMany(Game::class)->withPivot(['player_id']);
    }

    public function matchArchetypes(): HasMany
    {
        return $this->hasMany(MatchArchetype::class, 'player_id');
    }
}
