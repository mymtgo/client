<?php

namespace App\Models;

use App\Enums\TournamentState;
use App\Enums\TournamentStructure;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Challenge extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'state' => TournamentState::class,
        'tournament_structure' => TournamentStructure::class,
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'participated' => 'boolean',
    ];

    /** @return HasMany<ChallengeStanding, $this> */
    public function standings(): HasMany
    {
        return $this->hasMany(ChallengeStanding::class);
    }

    /** @return HasMany<ChallengeTimelineEvent, $this> */
    public function timelineEvents(): HasMany
    {
        return $this->hasMany(ChallengeTimelineEvent::class);
    }

    /** @return HasMany<MtgoMatch, $this> */
    public function matches(): HasMany
    {
        return $this->hasMany(MtgoMatch::class);
    }

    public function scopeActive($query)
    {
        return $query->where('state', '!=', TournamentState::Completed);
    }

    public function scopeCompleted($query)
    {
        return $query->where('state', TournamentState::Completed);
    }

    public function scopeParticipated($query)
    {
        return $query->where('participated', true);
    }

    public function scopeForFormat($query, string $format)
    {
        return $query->where('format', $format);
    }

    public function localStanding(): ?ChallengeStanding
    {
        return $this->standings()
            ->where('is_local', true)
            ->orderByDesc('round')
            ->first();
    }
}
