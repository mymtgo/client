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
        if (! $this->signature) {
            return [];
        }

        $decoded = base64_decode($this->signature, true);

        if ($decoded === false) {
            return [];
        }

        $segments = collect(explode('|', $decoded))
            ->filter()
            ->map(function (string $cardSig) {
                $parts = explode(':', $cardSig);

                if (count($parts) < 3) {
                    return null;
                }

                return $parts;
            })
            ->filter()
            ->values();

        if ($segments->isEmpty()) {
            return [];
        }

        $isNewFormat = is_numeric($segments->first()[0]);

        if (! $isNewFormat) {
            return $segments->map(fn ($parts) => [
                'oracle_id' => $parts[0],
                'quantity' => $parts[1],
                'sideboard' => $parts[2],
            ])->values()->toArray();
        }

        $mtgoIds = $segments->map(fn ($parts) => (int) $parts[0])->unique()->values();

        $oracleByMtgoId = Card::whereIn('mtgo_id', $mtgoIds)
            ->get(['mtgo_id', 'oracle_id'])
            ->keyBy('mtgo_id');

        return $segments->map(function ($parts) use ($oracleByMtgoId) {
            $mtgoId = (int) $parts[0];

            return [
                'oracle_id' => $oracleByMtgoId->get($mtgoId)?->oracle_id,
                'mtgo_id' => $mtgoId,
                'quantity' => $parts[1],
                'sideboard' => $parts[2],
            ];
        })->values()->toArray();
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
