<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $match_archetypes_count
 * @property-read Collection<int, Card> $cards
 */
class Archetype extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'decklist_downloaded_at' => 'datetime',
        'manual' => 'boolean',
    ];

    public function matchArchetypes(): HasMany
    {
        return $this->hasMany(MatchArchetype::class, 'archetype_id');
    }

    public function cards(): BelongsToMany
    {
        return $this->belongsToMany(Card::class, 'archetype_cards')
            ->using(ArchetypeCard::class)
            ->withPivot('quantity', 'sideboard')
            ->withTimestamps();
    }
}
