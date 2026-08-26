<?php

namespace App\Models;

use App\Enums\DraftState;
use Carbon\CarbonInterface;
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
 * @property int $picks_made_count Only set when the picks-made withCount alias is applied.
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $ended_at
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

    /**
     * Distinct picked catalog ids in pick order.
     *
     * @return array<int, int>
     */
    public function pickedCatalogIds(): array
    {
        return $this->picks()
            ->whereNotNull('picked_catalog_id')
            ->pluck('picked_catalog_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
