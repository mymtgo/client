<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Game extends Model
{
    protected $fillable = ['match_id', 'mtgo_id', 'started_at', 'ended_at', 'won'];

    protected $casts = [
        'won' => 'boolean',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(MtgoMatch::class, 'match_id');
    }

    public function players(): BelongsToMany
    {
        return $this->belongsToMany(Player::class)
            ->using(GamePlayer::class)
            ->withPivot(['on_play', 'instance_id', 'starting_hand_size', 'deck_json', 'is_local']);
    }

    public function localPlayers(): BelongsToMany
    {
        return $this->players()->wherePivot('is_local', 1);
    }

    public function opponents(): BelongsToMany
    {
        return $this->players()->wherePivot('is_local', 0);
    }

    public function deck(): HasOne
    {
        return $this->hasOne(GameDeck::class);
    }
}
