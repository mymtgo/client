<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Outbox extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'file_version' => 'integer',
            'attempts' => 'integer',
            'synced_version' => 'integer',
            'last_attempt_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<Outbox>  $query
     * @return Builder<Outbox>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }
}
