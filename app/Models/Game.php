<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Player> $players
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Player> $localPlayers
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Player> $opponents
 * @property-read \Illuminate\Database\Eloquent\Collection<int, GameTimeline> $timeline
 */
class Game extends Model
{
    use HasFactory;

    protected $fillable = ['match_id', 'mtgo_id', 'started_at', 'ended_at', 'won'];

    protected $casts = [
        'won' => 'boolean',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    /** @return BelongsTo<MtgoMatch, $this> */
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

    /** @return HasMany<GameTimeline, $this> */
    public function timeline(): HasMany
    {
        return $this->hasMany(GameTimeline::class);
    }
}
