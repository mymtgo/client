<?php

namespace App\Models;

use App\Enums\TournamentState;
use App\Enums\TournamentStructure;
use App\Enums\TournamentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tournament extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'state' => TournamentState::class,
        'tournament_structure' => TournamentStructure::class,
        'type' => TournamentType::class,
        'event_id' => 'integer',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'participated' => 'boolean',
    ];

    /** @return HasMany<TournamentStanding, $this> */
    public function standings(): HasMany
    {
        return $this->hasMany(TournamentStanding::class);
    }

    /** @return HasMany<TournamentTimelineEvent, $this> */
    public function timelineEvents(): HasMany
    {
        return $this->hasMany(TournamentTimelineEvent::class);
    }

    /** @return HasMany<MtgoMatch, $this> */
    public function matches(): HasMany
    {
        return $this->hasMany(MtgoMatch::class, 'tournament_id');
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

    public function localStanding(): ?TournamentStanding
    {
        return $this->standings()
            ->where('is_local', true)
            ->orderByDesc('round')
            ->first();
    }
}
