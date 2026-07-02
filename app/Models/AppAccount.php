<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AppAccount extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'mtgo_player_id' => 'integer',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<AppAccount>  $query
     * @return Builder<AppAccount>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
