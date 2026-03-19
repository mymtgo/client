<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameLog extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'decoded_entries' => 'array',
            'decoded_at' => 'datetime',
        ];
    }
}
