<?php

namespace App\Models;

use App\Enums\LeagueKind;
use App\Enums\LeagueState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $wins_count
 * @property int $losses_count
 * @property int $total_matches_count
 * @property int $total_count
 * @property int $won_count
 */
class League extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $attributes = [
        'kind' => 'constructed',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'joined_at' => 'datetime',
        'dropped_at' => 'datetime',
        'completed_at' => 'datetime',
        'state' => LeagueState::class,
        'manual' => 'boolean',
        'deck_change_detected' => 'boolean',
        'kind' => LeagueKind::class,
    ];

    /** @return BelongsTo<DeckVersion, $this> */
    public function deckVersion(): BelongsTo
    {
        return $this->belongsTo(DeckVersion::class);
    }

    /** @return HasMany<MtgoMatch, $this> */
    public function matches(): HasMany
    {
        return $this->hasMany(MtgoMatch::class);
    }

    /** @return HasOne<Draft, $this> */
    public function draft(): HasOne
    {
        return $this->hasOne(Draft::class);
    }

    /** @return HasMany<LimitedDeckSnapshot, $this> */
    public function deckSnapshots(): HasMany
    {
        return $this->hasMany(LimitedDeckSnapshot::class)->orderBy('captured_at');
    }

    /**
     * Draft and sealed leagues only.
     *
     * @param  Builder<League>  $query
     * @return Builder<League>
     */
    public function scopeLimited(Builder $query): Builder
    {
        return $query->whereIn('kind', [LeagueKind::Draft->value, LeagueKind::Sealed->value]);
    }
}
