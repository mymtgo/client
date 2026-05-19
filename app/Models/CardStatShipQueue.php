<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CardStatShipQueue extends Model
{
    protected $table = 'card_stat_ship_queue';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'next_attempt_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /** @return BelongsTo<MtgoMatch, $this> */
    public function match(): BelongsTo
    {
        return $this->belongsTo(MtgoMatch::class, 'match_id');
    }
}
