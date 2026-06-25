<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property-read Collection<int, GameTimeline> $timeline
 * @property-read Collection<int, GameDeck> $decks
 */
class Game extends Model
{
    use HasFactory;

    protected $fillable = [
        'match_id', 'mtgo_id', 'started_at', 'ended_at', 'won', 'turn_count',
        'local_on_play', 'local_mulligans', 'opp_mulligans',
        'local_dice', 'opp_dice', 'local_instance', 'opp_instance',
    ];

    protected $casts = [
        'won' => 'boolean',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'turn_count' => 'integer',
        'local_on_play' => 'boolean',
        'local_mulligans' => 'integer',
        'opp_mulligans' => 'integer',
        'local_dice' => 'integer',
        'opp_dice' => 'integer',
        'local_instance' => 'integer',
        'opp_instance' => 'integer',
    ];

    /** @return BelongsTo<MtgoMatch, $this> */
    public function match(): BelongsTo
    {
        return $this->belongsTo(MtgoMatch::class, 'match_id');
    }

    /** @return HasMany<GameTimeline, $this> */
    public function timeline(): HasMany
    {
        return $this->hasMany(GameTimeline::class);
    }

    /** @return HasMany<CardGameStat, $this> */
    public function cardGameStats(): HasMany
    {
        return $this->hasMany(CardGameStat::class);
    }

    /** @return HasOne<CardStatShipQueue, $this> */
    public function shipQueueEntry(): HasOne
    {
        return $this->hasOne(CardStatShipQueue::class);
    }

    /** @return HasMany<GameDeck, $this> */
    public function decks(): HasMany
    {
        return $this->hasMany(GameDeck::class);
    }

    /**
     * Returns the local player's deck snapshot for this game, or null if not yet recorded.
     * Reads from the eager-loaded `decks` collection when available; falls back to a query.
     */
    public function localDeck(): ?GameDeck
    {
        if ($this->relationLoaded('decks')) {
            return $this->decks->firstWhere('is_opponent', false);
        }

        return $this->decks()->where('is_opponent', false)->first();
    }

    /**
     * Returns the opponent's deck snapshot for this game, or null if not yet recorded.
     * Reads from the eager-loaded `decks` collection when available; falls back to a query.
     */
    public function opponentDeck(): ?GameDeck
    {
        if ($this->relationLoaded('decks')) {
            return $this->decks->firstWhere('is_opponent', true);
        }

        return $this->decks()->where('is_opponent', true)->first();
    }
}
