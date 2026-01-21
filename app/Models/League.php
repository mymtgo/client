<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class League extends Model
{
    protected $guarded = [];

    public function matches(): HasMany
    {
        return $this->hasMany(MtgoMatch::class);
    }
}
