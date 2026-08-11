<?php

namespace App\Actions\Debug;

use App\Enums\MatchState;
use App\Facades\Mtgo;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Support\Str;

class CreateFakeOverlayMatch
{
    public const TOKEN_PREFIX = 'fake-overlay-';

    /**
     * Create an in-progress match against a fake (or named real) opponent so
     * the game overlay can be reviewed without MTGO running.
     *
     * The match links the deck's latest real version, so draw odds, sideboard
     * stats and notes all read the user's genuine data. The opponent's
     * "revealed" cards are seeded from the archetype's decklist so the live
     * archetype guess actually fires.
     */
    public static function run(Deck $deck, Archetype $archetype, ?string $opponentName = null): MtgoMatch
    {
        $match = MtgoMatch::create([
            'mtgo_id' => 'fake-'.now()->timestamp,
            'token' => self::TOKEN_PREFIX.Str::lower(Str::random(8)),
            'format' => 'C'.strtoupper((string) $archetype->format),
            'match_type' => 'League',
            'state' => MatchState::InProgress,
            'started_at' => now(),
            'deck_version_id' => $deck->latestVersion?->id,
        ]);

        $game = Game::create([
            'match_id' => $match->id,
            'mtgo_id' => 'fake-g1-'.$match->id,
            'started_at' => now(),
        ]);

        $local = Player::firstOrCreate(['username' => Mtgo::getUsername() ?? 'You']);
        $opponent = Player::firstOrCreate(['username' => $opponentName ?: 'FakeOpponent']);

        // Local deck_json stays empty: ComputeDrawOdds falls back to the
        // match's deck version, which is the real list we linked above.
        $game->players()->attach($local->id, [
            'is_local' => 1,
            'instance_id' => 1,
            'deck_json' => [],
        ]);

        $game->players()->attach($opponent->id, [
            'is_local' => 0,
            'instance_id' => 2,
            'deck_json' => self::revealedCards($archetype),
        ]);

        return $match;
    }

    /**
     * A plausible mid-game reveal: a dozen distinct maindeck cards from the
     * archetype's most recently synced decklist, quantities capped at 4.
     *
     * @return array<int, array{mtgo_id: int, quantity: int}>
     */
    private static function revealedCards(Archetype $archetype): array
    {
        $decklist = $archetype->decks()
            ->orderByDesc('last_synced_at')
            ->orderByDesc('created_at')
            ->first();

        if (! $decklist) {
            return [];
        }

        return $decklist->cards
            ->filter(fn ($card) => ! $card->pivot->sideboard && $card->mtgo_id !== null)
            ->take(12)
            ->map(fn ($card) => [
                'mtgo_id' => (int) $card->mtgo_id,
                'quantity' => min(4, (int) $card->pivot->quantity),
            ])
            ->values()
            ->all();
    }
}
