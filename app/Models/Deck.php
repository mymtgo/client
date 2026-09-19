<?php

namespace App\Models;

use App\Actions\Limited\EnsureLimitedDeckVersion;
use App\Enums\MatchState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $won_matches_count
 * @property int $lost_matches_count
 * @property int $matches_count
 * @property string|null $matches_max_started_at
 * @property-read Collection<int, DeckVersion> $versions
 * @property-read Collection<int, MtgoMatch> $matches
 * @property-read Collection|null $cards
 */
class Deck extends Model
{
    use HasFactory, SoftDeletes;

    public const LOCAL_LIMITED_PREFIX = 'limited:league-';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'deck_file_synced_at' => 'datetime',
        ];
    }

    /** @return HasMany<DeckVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(DeckVersion::class);
    }

    /** @return HasMany<SideboardGuide, $this> */
    public function sideboardGuides(): HasMany
    {
        return $this->hasMany(SideboardGuide::class);
    }

    /** @return HasMany<DeckArchetypeNote, $this> */
    public function archetypeNotes(): HasMany
    {
        return $this->hasMany(DeckArchetypeNote::class);
    }

    /** @return HasOne<DeckVersion, $this> */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(DeckVersion::class, 'deck_id')->latestOfMany('modified_at');
    }

    /** @return HasManyThrough<MtgoMatch, DeckVersion, $this> */
    public function matches(): HasManyThrough
    {
        return $this->hasManyThrough(MtgoMatch::class, DeckVersion::class, 'deck_id', 'deck_version_id')->where('state', MatchState::Complete);
    }

    public function lostMatches(): HasManyThrough
    {
        return $this->matches()->where('outcome', 'loss');
    }

    public function wonMatches(): HasManyThrough
    {
        return $this->matches()->where('outcome', 'win');
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Card, $this> */
    public function cover(): BelongsTo
    {
        return $this->belongsTo(Card::class, 'cover_id');
    }

    /** @return BelongsTo<Archetype, $this> */
    public function archetype(): BelongsTo
    {
        return $this->belongsTo(Archetype::class);
    }

    public function scopeForActiveAccount(Builder $query): Builder
    {
        $query = $query->withTrashed();
        $accountId = Account::currentId();

        if ($accountId) {
            return $query->where('account_id', $accountId);
        }

        return $query;
    }

    public function isLimited(): bool
    {
        return $this->format === EnsureLimitedDeckVersion::FORMAT
            || str_starts_with((string) $this->mtgo_id, 'limited:');
    }

    /**
     * Constructed decks only. Limited decks sync on the supporter tier alone.
     *
     * @param  Builder<Deck>  $query
     * @return Builder<Deck>
     */
    public function scopeWithoutLimited(Builder $query): Builder
    {
        return $query->where('mtgo_id', 'not like', 'limited:%');
    }

    /**
     * Decks whose cross-device identity is stable enough to sync. The local-only
     * limited shape is keyed on an autoincrement, so it would collide across
     * devices.
     *
     * @param  Builder<Deck>  $query
     * @return Builder<Deck>
     */
    public function scopeSyncableIdentity(Builder $query): Builder
    {
        return $query->where('mtgo_id', 'not like', self::LOCAL_LIMITED_PREFIX.'%');
    }
}
