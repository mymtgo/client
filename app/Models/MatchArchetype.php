<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|null $wins
 * @property int|null $losses
 * @property int|null $total
 * @property bool $manual
 * @property-read Archetype|null $archetype
 */
class MatchArchetype extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'manual' => 'boolean',
        ];
    }

    /** @return BelongsTo<MtgoMatch, $this> */
    public function match(): BelongsTo
    {
        return $this->belongsTo(MtgoMatch::class, 'mtgo_match_id');
    }

    /** @return BelongsTo<Archetype, $this> */
    public function archetype(): BelongsTo
    {
        return $this->belongsTo(Archetype::class, 'archetype_id');
    }

    /** @return BelongsTo<ArchetypeDeck, $this> */
    public function archetypeDeck(): BelongsTo
    {
        return $this->belongsTo(ArchetypeDeck::class);
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id');
    }
}
