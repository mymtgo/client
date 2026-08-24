<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $body
 * @property-read Deck $deck
 * @property-read Archetype|null $archetype
 */
class DeckArchetypeNote extends Model
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
}
