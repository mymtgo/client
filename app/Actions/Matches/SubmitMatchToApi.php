<?php

namespace App\Actions\Matches;

use App\Actions\RegisterDevice;
use App\Facades\AppSettings;
use App\Models\Card;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SubmitMatchToApi
{
    public static function run(int $matchId): void
    {
        if (! AppSettings::shouldTransmitMatches()) {
            return;
        }

        $match = MtgoMatch::with(['league', 'archetypes.archetype', 'games.timeline', 'games.decks'])->find($matchId);

        if (! $match) {
            return;
        }

        if ($match->submitted_at !== null) {
            return;
        }

        if (! $match->deck_version_id) {
            return;
        }

        $opponentArchetype = $match->opponentArchetypes()->with('archetype')->first();

        $playerArchetype = $match->archetypes()
            ->where('is_opponent', false)
            ->with('archetype')
            ->first();

        if (! $playerArchetype?->archetype) {
            return;
        }

        $isTournament = $match->tournament_event_id !== null;
        $leagueToken = $match->league?->token;

        $deckVersion = DeckVersion::find($match->deck_version_id);
        $deck = self::buildDeckPayload($deckVersion);

        $gamesPayload = $match->games
            ->sortBy('started_at')
            ->values()
            ->map(fn ($game, $index) => [
                'game_number' => $index + 1,
                ...ExtractGameHandData::run($game),
            ])
            ->toArray();

        $payload = [
            'match_token' => $match->token,
            'username' => $match->account?->username ?? '',
            'player_archetype_uuid' => $playerArchetype->archetype->uuid,
            'opponent_archetype_uuid' => $opponentArchetype?->archetype?->uuid,
            'result' => $match->isWin() ? 'win' : 'loss',
            'format' => $match->format,
            'is_tournament' => $isTournament,
            'league_token' => $leagueToken,
            'league_run' => $match->league?->id,
            'challenge_token' => $match->tournament_token,
            'tournament_round' => $match->tournament_round,
            'played_at' => $match->started_at->toIso8601String(),
            'deck' => $deck,
            'opponent_deck' => self::buildOpponentDeckPayload($match),
            'games' => $gamesPayload,
        ];

        try {
            $response = self::authenticatedRequest()
                ->post(config('mymtgo_api.url').'/api/matches/report', $payload);

            if ($response->status() === 401) {
                RegisterDevice::run();
                $response = self::authenticatedRequest()
                    ->post(config('mymtgo_api.url').'/api/matches/report', $payload);
            }

            if ($response->successful()) {
                $match->update(['submitted_at' => now()]);
            } else {
                Log::warning('SubmitMatchToApi: non-2xx response', [
                    'match_id' => $matchId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('SubmitMatchToApi: exception', [
                'match_id' => $matchId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function authenticatedRequest(): PendingRequest
    {
        return Http::withHeaders([
            'X-Device-Id' => AppSettings::deviceId(),
            'X-Api-Key' => RegisterDevice::retrieveKey(),
        ]);
    }

    /**
     * Aggregate known opponent cards seen across the match's games.
     *
     * Sums quantities per mtgo_id (capped at 4) from each opponent
     * game's game_decks row (via game->opponentDeck()). Seen cards carry no reliable sideboard
     * signal, so all entries are reported in the main zone.
     *
     * @return array<int, array{mtgo_id: int, quantity: int, zone: string}>
     */
    private static function buildOpponentDeckPayload(MtgoMatch $match): array
    {
        return $match->games
            ->flatMap(fn ($game) => $game->opponentDeck()?->deck_json ?? [])
            ->filter(fn ($card) => ! empty($card['mtgo_id']))
            ->groupBy('mtgo_id')
            ->map(fn ($group, $mtgoId) => [
                'mtgo_id' => (int) $mtgoId,
                'quantity' => min(4, $group->sum('quantity')),
                'zone' => 'main',
            ])
            ->values()
            ->all();
    }

    private static function buildDeckPayload(?DeckVersion $deckVersion): array
    {
        if (! $deckVersion) {
            return [];
        }

        $cards = $deckVersion->cards;
        $oracleIds = collect($cards)->pluck('oracle_id')->unique()->toArray();

        $cardMap = Card::whereIn('oracle_id', $oracleIds)
            ->get(['oracle_id', 'mtgo_id'])
            ->keyBy('oracle_id');

        return collect($cards)
            ->map(function (array $card) use ($cardMap) {
                $record = $cardMap->get($card['oracle_id']);

                if (! $record) {
                    return null;
                }

                return [
                    'mtgo_id' => (int) $record->mtgo_id,
                    'quantity' => (int) $card['quantity'],
                    'zone' => $card['sideboard'] === 'false' ? 'main' : 'side',
                ];
            })
            ->filter()
            ->values()
            ->toArray();
    }
}
