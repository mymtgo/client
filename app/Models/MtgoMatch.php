<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Native\Laravel\Facades\Settings;

class MtgoMatch extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'games_won' => 'integer',
        'games_lost' => 'integer',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function getTable()
    {
        return 'matches';
    }

    public function games(): HasMany
    {
        return $this->hasMany(Game::class, 'match_id', 'id');
    }

    public function deck(): HasOneThrough
    {
        return $this->hasOneThrough(
            Deck::class,          // final model
            DeckVersion::class,   // through model
            'id',                 // through PK referenced by matches.deck_version_id
            'id',                 // decks PK referenced by deck_versions.deck_id
            'deck_version_id',    // local key on matches
            'deck_id'             // foreign key on deck_versions to decks
        );
    }

    public function opponentDecks(): HasManyThrough
    {
        return $this->hasManyThrough(GameDeck::class, Game::class, 'match_id', 'game_id', 'id', 'id')
            ->whereHas('player', function ($query) {
                $query->where('username', '!=', Settings::get('mtgo_username'));
            });
    }

    public function archetypes(): HasMany
    {
        return $this->hasMany(MatchArchetype::class);
    }

    public function opponentArchetypes(): HasMany
    {
        return $this->hasMany(MatchArchetype::class)
            ->whereIn('player_id', function ($q) {
                $q->select('gp.player_id')
                    ->from('game_player as gp')
                    ->join('games as g', 'g.id', '=', 'gp.game_id')
                    ->whereRaw('g.match_id = match_id')
                    ->where('gp.is_local', false)
                    ->distinct();
            });
    }

    public function isCompleted(): bool
    {
        return $this->status != 'in_progress';
    }

    public function getMatchTimeAttribute()
    {
        return $this->ended_at->diffForHumans($this->started_at, CarbonInterface::DIFF_ABSOLUTE);
    }

    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }
}
