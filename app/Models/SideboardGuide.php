<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A player-authored sideboard plan for one deck against one opponent archetype.
 *
 * Notes for the same matchup live in deck_archetype_notes keyed on the same
 * (deck_id, archetype_id) pair; fetch them with GetArchetypeNotes rather than a
 * relation, since Eloquent has no composite-key hasMany.
 *
 * @property int $id
 * @property int $deck_id
 * @property int $archetype_id
 * @property-read Deck $deck
 * @property-read Archetype $archetype
 * @property-read Collection<int, SideboardGuideCard> $cards
 */
class SideboardGuide extends Model
{
    use HasFactory;

    protected $guarded = [];

    /** @return BelongsTo<Deck, $this> */
    public function deck(): BelongsTo
    {
        return $this->belongsTo(Deck::class)->withTrashed();
    }

    /** @return BelongsTo<Archetype, $this> */
    public function archetype(): BelongsTo
    {
        return $this->belongsTo(Archetype::class);
    }

    /** @return HasMany<SideboardGuideCard, $this> */
    public function cards(): HasMany
    {
        return $this->hasMany(SideboardGuideCard::class);
    }
}
