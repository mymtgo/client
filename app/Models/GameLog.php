<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<int, array{timestamp: string, message: string}>|null $decoded_entries
 * @property string[]|null $players
 */
class GameLog extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'decoded_entries' => 'array',
            'decoded_at' => 'datetime',
            'first_timestamp' => 'datetime',
            'players' => 'array',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(MtgoMatch::class, 'match_token', 'token');
    }
}
