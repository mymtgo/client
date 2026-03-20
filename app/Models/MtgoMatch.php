<?php

namespace App\Models;

use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Observers\MtgoMatchObserver;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

#[ObservedBy(MtgoMatchObserver::class)]
class MtgoMatch extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'outcome' => MatchOutcome::class,
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'submitted_at' => 'datetime',
        'state' => MatchState::class,
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
                    ->whereColumn('g.match_id', 'match_archetypes.mtgo_match_id')
                    ->where('gp.is_local', false)
                    ->distinct();
            });
    }

    public function scopeSubmittable(Builder $query): Builder
    {
        return $query->where('state', MatchState::Complete)
            ->whereNull('submitted_at')
            ->whereNotNull('deck_version_id')
            ->whereHas('archetypes');
    }

    public function scopeComplete(Builder $query): Builder
    {
        return $query->where('state', MatchState::Complete);
    }

    public function scopeIncomplete(Builder $query): Builder
    {
        return $query->whereNotIn('state', [MatchState::Complete, MatchState::Voided]);
    }

    public static function displayFormat(string $format): string
    {
        // MTGO format codes are prefixed with 'C' (e.g. CModern, CStandard)
        $raw = preg_match('/^C[A-Z]/', $format) ? substr($format, 1) : $format;

        return \Illuminate\Support\Str::title(strtolower($raw));
    }

    public function isCompleted(): bool
    {
        return $this->state === MatchState::Complete;
    }

    public function getMatchTimeAttribute()
    {
        return $this->ended_at?->diffForHumans($this->started_at, CarbonInterface::DIFF_ABSOLUTE);
    }

    public function deckVersion(): BelongsTo
    {
        return $this->belongsTo(DeckVersion::class, 'deck_version_id');
    }

    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }

    public function gamesWon(): int
    {
        return $this->games()->where('won', true)->count();
    }

    public function gamesLost(): int
    {
        return $this->games()->where('won', false)->count();
    }

    /**
     * Find a match using whatever identifiers are available on the LogEvent.
     * Tries match_token first (exact match on token), then match_id (on mtgo_id).
     */
    public static function findByEvent(LogEvent $event): ?static
    {
        if ($event->match_token) {
            $match = static::where('token', $event->match_token)->first();
            if ($match) {
                return $match;
            }
        }

        if ($event->match_id) {
            $match = static::where('mtgo_id', $event->match_id)->first();
            if ($match) {
                return $match;
            }
        }

        return null;
    }

    /**
     * Determine the match outcome from game results.
     */
    public static function determineOutcome(int $wins, int $losses): MatchOutcome
    {
        if ($wins > $losses) {
            return MatchOutcome::Win;
        }

        if ($losses > $wins) {
            return MatchOutcome::Loss;
        }

        if ($wins > 0 && $wins === $losses) {
            return MatchOutcome::Draw;
        }

        return MatchOutcome::Unknown;
    }
}
