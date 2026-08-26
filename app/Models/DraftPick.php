<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $ordinal
 * @property int $pack_number
 * @property int $pick_number
 * @property array<int, int> $cards_available
 * @property array<int, array{catalog_id: int, at?: string}> $reservations
 * @property int|null $picked_catalog_id
 * @property CarbonInterface|null $shown_at
 * @property CarbonInterface|null $deadline_at
 * @property CarbonInterface|null $picked_at
 */
class DraftPick extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = [
        'reservations' => '[]',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cards_available' => 'array',
            'reservations' => 'array',
            'shown_at' => 'datetime',
            'deadline_at' => 'datetime',
            'picked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Draft, $this> */
    public function draft(): BelongsTo
    {
        return $this->belongsTo(Draft::class);
    }
}
