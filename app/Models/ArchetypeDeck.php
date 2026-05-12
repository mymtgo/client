<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property string $uuid
 * @property int $seen_count
 * @property Carbon|null $last_synced_at
 * @property-read Archetype $archetype
 * @property-read Collection<int, Card> $cards
 */
class ArchetypeDeck extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
            'seen_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Archetype, $this> */
    public function archetype(): BelongsTo
    {
        return $this->belongsTo(Archetype::class);
    }

    /** @return BelongsToMany<Card, $this> */
    public function cards(): BelongsToMany
    {
        return $this->belongsToMany(Card::class, 'archetype_deck_cards')
            ->using(ArchetypeDeckCard::class)
            ->withPivot('quantity', 'sideboard')
            ->withTimestamps();
    }
}
