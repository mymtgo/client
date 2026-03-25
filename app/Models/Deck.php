<?php

namespace App\Models;

use App\Enums\MatchState;
use Illuminate\Database\Eloquent\Builder;
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
 * @property-read \Illuminate\Database\Eloquent\Collection<int, DeckVersion> $versions
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MtgoMatch> $matches
 * @property-read \Illuminate\Database\Eloquent\Collection|null $cards
 */
class Deck extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    /** @return HasMany<DeckVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(DeckVersion::class);
    }

    /** @return HasOne<DeckVersion, $this> */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(DeckVersion::class, 'deck_id')->latestOfMany('modified_at');
    }

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

    public function scopeForActiveAccount(Builder $query): Builder
    {
        $accountId = Account::active()->value('id');

        if ($accountId) {
            return $query->where('account_id', $accountId);
        }

        return $query;
    }
}
