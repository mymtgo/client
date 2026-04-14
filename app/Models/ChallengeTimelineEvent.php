<?php

namespace App\Models;

use App\Enums\ChallengeTimelineEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChallengeTimelineEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'event_type' => ChallengeTimelineEventType::class,
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    /** @return BelongsTo<Challenge, $this> */
    public function challenge(): BelongsTo
    {
        return $this->belongsTo(Challenge::class);
    }
}
