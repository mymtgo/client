<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameDeck extends Model
{
    use HasFactory;

    protected $fillable = ['game_id', 'is_opponent', 'deck_json'];

    protected function casts(): array
    {
        return [
            'is_opponent' => 'bool',
            'deck_json' => 'array',
        ];
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
