<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tournament extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'mtgo_event_id' => 'integer',
            'started_at' => 'datetime',
            'name_synthesized' => 'boolean',
        ];
    }

    /** @return HasMany<MtgoMatch, $this> */
    public function matches(): HasMany
    {
        return $this->hasMany(MtgoMatch::class);
    }
}
