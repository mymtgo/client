<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $match_archetypes_count
 * @property bool $is_fallback
 * @property bool $manual
 * @property string|null $format
 * @property-read Collection<int, Card> $cards
 */
class Archetype extends Model
{
    use HasFactory;

    public const HOMEBREW_UUID = '00000000-0000-0000-0000-000000000001';

    public const ROGUE_UUID = '00000000-0000-0000-0000-000000000002';

    protected $guarded = [];

    protected $casts = [
        'decklist_downloaded_at' => 'datetime',
        'manual' => 'boolean',
        'is_fallback' => 'boolean',
        'incomplete' => 'boolean',
    ];

    public function sourceMatch(): BelongsTo
    {
        return $this->belongsTo(MtgoMatch::class, 'source_match_id');
    }

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

    public function scopeForFormat(Builder $query, ?string $format): Builder
    {
        return $query->where(function (Builder $inner) use ($format) {
            $inner->where('format', $format)->orWhere('is_fallback', true);
        });
    }
}
