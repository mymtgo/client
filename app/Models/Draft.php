<?php

namespace App\Models;

use App\Enums\DraftState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One booster draft. Belongs to a League (v1) or a Tournament (reserved).
 *
 * @property int|null $league_id
 * @property string $draft_token
 * @property DraftState $state
 * @property int $seat_count
 * @property int $pack_size
 * @property int $picks_expected
 */
class Draft extends Model
{
    use HasFactory;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'state' => DraftState::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<League, $this> */
    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }

    /** @return HasMany<DraftPick, $this> */
    public function picks(): HasMany
    {
        return $this->hasMany(DraftPick::class)->orderBy('ordinal');
    }

    /**
     * Multiset of picked catalog ids, for pool comparisons.
     *
     * @return array<int, int> catalog_id => quantity
     */
    public function poolCounts(): array
    {
        return $this->picks()
            ->whereNotNull('picked_catalog_id')
            ->pluck('picked_catalog_id')
            ->countBy()
            ->all();
    }
}
