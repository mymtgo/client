<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Player extends Model
{
    protected $fillable = ['username'];

    public function games(): BelongsToMany
    {
        return $this->belongsToMany(Game::class)->withPivot(['player_id']);
    }
}
