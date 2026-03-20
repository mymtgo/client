<?php

namespace App\Models;

use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Deck extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    public function versions(): HasMany
    {
        return $this->hasMany(DeckVersion::class);
    }

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
        return $this->matches()->where('outcome', MatchOutcome::Loss);
    }

    public function wonMatches(): HasManyThrough
    {
        return $this->matches()->where('outcome', MatchOutcome::Win);
    }

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
