<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TournamentObservationQueue extends Model
{
    protected $table = 'tournament_observation_queue';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'client_observed_at' => 'datetime',
        'next_attempt_at' => 'datetime',
    ];

    /** @return BelongsTo<LogEvent, $this> */
    public function logEvent(): BelongsTo
    {
        return $this->belongsTo(LogEvent::class);
    }
}
