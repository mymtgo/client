<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class League extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
    ];

    public function matches(): HasMany
    {
        return $this->hasMany(MtgoMatch::class);
    }
}
