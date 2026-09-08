<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class GamePlayer extends Pivot
{
    protected $casts = [
        'is_local' => 'bool',
        'on_play' => 'bool',
        'deck_json' => 'array',
        'opening_hand_json' => 'array',
        'mulligan_count' => 'integer',
    ];
}
