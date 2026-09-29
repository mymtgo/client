<?php

namespace App\Actions\Matches;

use App\Actions\RecoverFromUnauthorized;
use App\Exceptions\OfflineModeException;
use App\Facades\AppSettings;
use App\Models\Account;
use App\Models\Archetype;
use App\Models\Card;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\MtgoMatch;
use App\Sidecar\SidecarTables;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SubmitMatchToApi
{
    /**
     * How long a report waits for the sidecar to finish projecting the match
     * (clocks, concede results) before going out with whatever is written.
     */
    public const SIDECAR_WAIT_SECONDS = 120;

    public static function run(int $matchId): void
    {
        if (AppSettings::isOffline()) {
            return;
        }

        $match = MtgoMatch::with(['league', 'archetypes.archetype', 'games.timeline', 'games.players'])->find($matchId);

        if (! $match) {
            return;
        }

        if ($match->submitted_at !== null) {
            return;
        }

        if (! $match->deck_version_id) {
            return;
        }

        // Limited play is never reported. A 40-card draft pool has no
        // archetype and no counterpart report to pair with, so it can only
        // ever be a row the aggregates discard. scopeSubmittable filters
        // these out too; this guard covers the direct-dispatch callers.
        if ($match->isLimitedFormat()) {
            return;
        }

        // Manual matches are typed in by the user with no log behind them.
        // They stay local only; scopeSubmittable filters them too, this guard
        // covers direct-dispatch callers.
        if ($match->manual) {
            return;
        }

        if (self::sidecarStillWriting($match)) {
            return;
        }

        $opponentArchetype = $match->opponentArchetypes()->with('archetype')->first();
        $opponentPlayerIds = $match->opponentArchetypes()->pluck('player_id')->toArray();

        $playerArchetype = $match->archetypes()
            ->whereNotIn('player_id', $opponentPlayerIds)
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
                'turn_count' => $game->turn_count,
                'started_at' => $game->started_at?->toIso8601String(),
                'ended_at' => $game->ended_at?->toIso8601String(),
                ...ExtractGameHandData::run($game),
                ...self::clockPayload($game),
            ])
            ->toArray();

        $username = $match->games->first()->localPlayers->first()->username;

        $payload = [
            'match_token' => $match->token,
            'username' => $username,
            // The MTGO account id for the reporting seat, when this client
            // has seen that account log in. The API keys players on it;
            // null falls back to matching by username on the server.
            'login_id' => Account::where('username', $username)->value('login_id'),
            'player_archetype_uuid' => self::reportableUuid($playerArchetype->archetype),
            'opponent_archetype_uuid' => self::reportableUuid($opponentArchetype?->archetype),
            'result' => $match->isWin() ? 'win' : 'loss',
            'format' => $match->format,
            'is_tournament' => $isTournament,
            'league_token' => $leagueToken,
            'league_run' => $match->league?->id,
            'challenge_token' => $match->tournament_token,
            'tournament_round' => $match->tournament_round,
            'played_at' => $match->started_at->toIso8601String(),
            'client_version' => config('nativephp.version'),
            'opponent_name' => $match->games->first()?->players->first(fn ($player) => ! $player->pivot->is_local)?->username,
            'deck' => $deck,
            'opponent_deck' => self::buildOpponentDeckPayload($match),
            'games' => $gamesPayload,
        ];

        try {
            $response = Http::mymtgoApi()->post('/api/matches/report', $payload);

            if ($response->status() === 401) {
                RecoverFromUnauthorized::run();
                $response = Http::mymtgoApi()->post('/api/matches/report', $payload);
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
        } catch (OfflineModeException) {
            // Offline mode was toggled on between the early-return check above
            // and this request. A user choice, not a fault — skip quietly,
            // the match stays unsubmitted and is retried whenever this runs
            // again with offline mode off.
        } catch (\Throwable $e) {
            Log::error('SubmitMatchToApi: exception', [
                'match_id' => $matchId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The archetype uuid the API can resolve, or null.
     *
     * Two kinds of uuid never survive the trip. An archetype created on this
     * machine carries a device-prefixed uuid from StoreManualArchetype, which
     * the API validates as `nullable|uuid` and rejects — 422ing the whole
     * report, so the match never lands and scopeSubmittable re-queues it
     * forever. The Homebrew and Rogue fallbacks are client-side seeds the API
     * has no row for, so they store as a seat that can never join and drop the
     * match out of every matchup built from it.
     *
     * Null costs nothing either way: the API re-derives both seats from the
     * decks carried in the same payload.
     */
    private static function reportableUuid(?Archetype $archetype): ?string
    {
        if ($archetype === null || $archetype->manual || $archetype->is_fallback) {
            return null;
        }

        return $archetype->uuid;
    }

    /**
     * A submitted match is never re-sent, so a report that beats the sidecar
     * keeps null clocks and a pre-correction result for good. Hold it while
     * the match has unprocessed sidecar events, or has events but no
     * match_ended yet (the log can complete the match a tick before the
     * sidecar's final events are flushed and ingested). The once-a-minute
     * retry brings it back. Capped so a stuck sidecar never blocks
     * reporting. With no end time, the last write stands in for it. No
     * sidecar tables, or no events for this match, means nothing to wait for.
     */
    private static function sidecarStillWriting(MtgoMatch $match): bool
    {
        if (! SidecarTables::ready()) {
            return false;
        }

        $settledAt = $match->ended_at ?? $match->updated_at;

        if ($settledAt !== null && $settledAt->lt(now()->subSeconds(self::SIDECAR_WAIT_SECONDS))) {
            return false;
        }

        $events = GameEvent::query()->where('match_mtgo_id', (string) $match->mtgo_id);

        if (! (clone $events)->exists()) {
            return false;
        }

        return (clone $events)->whereNull('processed_at')->exists()
            || ! (clone $events)->where('type', 'match_ended')->exists();
    }

    /**
     * Each side's clock at the end of the game and whether it ran out.
     * Sidecar-only: without it every value is null.
     *
     * @return array{clock_remaining_ms: ?int, opponent_clock_remaining_ms: ?int, timed_out: ?bool, opponent_timed_out: ?bool}
     */
    private static function clockPayload(Game $game): array
    {
        $clockFor = fn (bool $local): ?int => $game->players
            ->first(fn ($player) => (bool) $player->pivot->is_local === $local)
            ?->pivot->clock_remaining_ms_end;

        return [
            'clock_remaining_ms' => $clockFor(true),
            'opponent_clock_remaining_ms' => $clockFor(false),
            'timed_out' => DetectGameTimeout::run($game, local: true),
            'opponent_timed_out' => DetectGameTimeout::run($game, local: false),
        ];
    }

    /**
     * Aggregate known opponent cards seen across the match's games.
     *
     * Sums quantities per mtgo_id (capped at 4) from each opponent
     * game_player's deck_json. Seen cards carry no reliable sideboard
     * signal, so all entries are reported in the main zone.
     *
     * @return array<int, array{mtgo_id: int, quantity: int, zone: string}>
     */
    private static function buildOpponentDeckPayload(MtgoMatch $match): array
    {
        return $match->games
            ->flatMap(fn ($game) => $game->players
                ->filter(fn ($player) => ! $player->pivot->is_local)
                ->flatMap(fn ($player) => $player->pivot->deck_json ?? []))
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
