<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChallengeStanding extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_local' => 'boolean',
        'opponent_match_win_pct' => 'float',
        'game_win_pct' => 'float',
    ];

    /** @return BelongsTo<Challenge, $this> */
    public function challenge(): BelongsTo
    {
        return $this->belongsTo(Challenge::class);
    }
}
