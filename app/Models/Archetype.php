<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Archetype extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'decklist_downloaded_at' => 'datetime',
    ];

    public function matchArchetypes(): HasMany
    {
        return $this->hasMany(MatchArchetype::class, 'archetype_id');
    }

    public function cards(): BelongsToMany
    {
        return $this->belongsToMany(Card::class, 'archetype_cards')
            ->withPivot('quantity', 'sideboard')
            ->withTimestamps();
    }
}
