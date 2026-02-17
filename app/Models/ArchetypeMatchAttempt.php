<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArchetypeMatchAttempt extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'response' => 'array',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(MtgoMatch::class, 'match_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function archetype(): BelongsTo
    {
        return $this->belongsTo(Archetype::class);
    }
}
