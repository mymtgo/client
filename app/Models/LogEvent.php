<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogEvent extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'logged_at' => 'datetime',
    ];

    public function logInstance(): BelongsTo
    {
        return $this->belongsTo(LogInstance::class);
    }
}
