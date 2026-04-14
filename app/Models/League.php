<?php

namespace App\Models;

use App\Enums\LeagueState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    protected $casts = [
        'started_at' => 'datetime',
        'joined_at' => 'datetime',
        'state' => LeagueState::class,
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
}
