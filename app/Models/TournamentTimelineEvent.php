<?php

namespace App\Models;

use App\Enums\TournamentTimelineEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TournamentTimelineEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'event_type' => TournamentTimelineEventType::class,
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    /** @return BelongsTo<Tournament, $this> */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }
}
