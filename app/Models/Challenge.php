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

    /**
     * Exclude limited events (Draft, Sealed, Cube, Queue) — these will have their own section.
     */
    public function scopeConstructedOnly($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('name')
                ->orWhere(function ($q2) {
                    $q2->where('name', 'not like', '%Draft%')
                        ->where('name', 'not like', '%Sealed%')
                        ->where('name', 'not like', '%Cube%')
                        ->where('name', 'not like', '%Queue%')
                        ->where('name', 'not like', 'Limited %');
                });
        });
    }

    public function localStanding(): ?ChallengeStanding
    {
        return $this->standings()
            ->where('is_local', true)
            ->orderByDesc('round')
            ->first();
    }
}
