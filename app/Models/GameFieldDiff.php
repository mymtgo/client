<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameFieldDiff extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'log_value' => 'array',
            'sidecar_value' => 'array',
        ];
    }

    /** @return BelongsTo<MtgoMatch, $this> */
    public function match(): BelongsTo
    {
        return $this->belongsTo(MtgoMatch::class, 'match_id');
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
