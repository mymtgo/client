<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeckVersion extends Model
{
    protected $guarded = [];

    protected $casts = [
        'modified_at' => 'datetime',
    ];

    public function getCardsAttribute(): array
    {
        $decoded = base64_decode($this->signature);

        return collect(
            explode('|', $decoded)
        )->map(function (string $cardSig) {
            $parts = explode(':', $cardSig);

            return [
                'oracle_id' => $parts[0],
                'quantity' => $parts[1],
                'sideboard' => $parts[2],
            ];
        })->toArray();
    }
}
