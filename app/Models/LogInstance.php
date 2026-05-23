<?php

namespace App\Models;

use Database\Factories\LogInstanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LogInstance extends Model
{
    /** @use HasFactory<LogInstanceFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'file_ctime' => 'integer',
            'anchor_offset' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'sealed_at' => 'datetime',
        ];
    }

    public function cursor(): HasOne
    {
        return $this->hasOne(LogCursor::class);
    }

    public function isSealed(): bool
    {
        return $this->sealed_at !== null;
    }

    protected static function newFactory(): LogInstanceFactory
    {
        return LogInstanceFactory::new();
    }
}
