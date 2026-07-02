<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RawArchive extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'byte_len' => 'integer',
        ];
    }
}
