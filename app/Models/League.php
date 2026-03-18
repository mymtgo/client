<?php

namespace App\Models;

use App\Enums\LeagueState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class League extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'state' => LeagueState::class,
    ];

    public function matches(): HasMany
    {
        return $this->hasMany(MtgoMatch::class);
    }
}
