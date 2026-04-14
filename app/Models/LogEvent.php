<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'logged_at' => 'datetime',
    ];
}
