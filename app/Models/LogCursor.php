<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogCursor extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_advanced_at' => 'datetime',
        ];
    }
}
