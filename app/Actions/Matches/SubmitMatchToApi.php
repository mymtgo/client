<?php

namespace App\Actions\Matches;

use App\Actions\RegisterDevice;
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
        if (! Settings::get('share_stats')) {
            return;
        }

        $match = MtgoMatch::with(['league', 'archetypes.archetype'])->find($matchId);

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
        $opponentPlayerIds = $match->opponentArchetypes()->pluck('player_id')->toArray();

        $playerArchetype = $match->archetypes()
            ->whereNotIn('player_id', $opponentPlayerIds)
            ->with('archetype')
            ->first();

        if (! $playerArchetype?->archetype || ! $opponentArchetype?->archetype) {
            return;
        }

        $isTournament = ! $match->league?->phantom;
        $leagueToken = $isTournament ? $match->league?->token : null;

        $deckVersion = DeckVersion::find($match->deck_version_id);
        $deck = self::buildDeckPayload($deckVersion);

        $payload = [
            'match_token' => $match->token,
            'username' => $match->games->first()->localPlayers->first()->username,
            'player_archetype_uuid' => $playerArchetype->archetype->uuid,
            'opponent_archetype_uuid' => $opponentArchetype->archetype->uuid,
            'result' => $match->games_won > $match->games_lost ? 'win' : 'loss',
            'format' => $match->format,
            'is_tournament' => $isTournament,
            'league_token' => $leagueToken,
            'challenge_token' => null,
            'played_at' => $match->started_at->toIso8601String(),
            'deck' => $deck,
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
            'X-Device-Id' => Settings::get('device_id'),
            'X-Api-Key' => RegisterDevice::retrieveKey(),
        ]);
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
