<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
        return $this->hasOne(DeckVersion::class, 'deck_id');
    }

    public function matches(): HasManyThrough
    {
        return $this->hasManyThrough(MtgoMatch::class, DeckVersion::class, 'deck_id', 'deck_version_id');
    }

    public function lostMatches(): HasManyThrough
    {
        return $this->matches()->whereRaw('games_lost > games_won');
    }

    public function wonMatches(): HasManyThrough
    {
        return $this->matches()->whereRaw('games_lost < games_won');
    }

    public function getWinrateAttribute()
    {
        $wins = $this->matches->filter(
            fn ($match) => $match->games_won > $match->games_lost
        )->count();

        $losses = $this->matches->filter(
            fn ($match) => $match->games_won < $match->games_lost
        )->count();

        return ($wins ? $wins / ($wins + $losses) : 0) * 100;
    }
}
