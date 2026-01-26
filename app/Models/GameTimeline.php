<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameTimeline extends Model
{
    protected $guarded = [];

    protected $casts = [
        'content' => 'array'
    ];
}
