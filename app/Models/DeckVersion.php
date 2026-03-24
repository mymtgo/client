<?php

namespace App\Models;

use App\Enums\MatchState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string|null $name
 */
class DeckVersion extends Model
{
    use HasFactory;

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

    /** @return BelongsTo<Deck, $this> */
    public function deck(): BelongsTo
    {
        return $this->belongsTo(Deck::class);
    }

    /** @return HasMany<MtgoMatch, $this> */
    public function matches(): HasMany
    {
        return $this->hasMany(MtgoMatch::class, 'deck_version_id')->where('state', MatchState::Complete);
    }
}
