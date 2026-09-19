<?php

declare(strict_types=1);

namespace App\Services\Sync\Bundles;

use App\Enums\MatchState;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;
use App\Models\Player;
use App\Services\Sync\LocalArchetypeId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Restores a match and its whole graph (games, timelines, card stats, game
 * players and archetype sides) from a canonical bundle.
 *
 * The import runs inside Model::withoutEvents() and one transaction.
 * MtgoMatchObserver dispatches enrichment jobs (archetype detection, stats
 * submission, card-stat recompute) on every state transition, and with the
 * $touches cascade live, a per-model save() per child row would fire
 * millions of parent touches on a cold sync. Every child collection inserts
 * via DB::table(...)->insert() in chunks of 500, never Eloquent save(): a
 * plain query builder insert bypasses both the event system and the touch
 * cascade, since neither is wired into it.
 *
 * synced_hash and synced_at are written last, after events are restored,
 * with saveQuietly() so that write alone stays quiet without re-entering
 * withoutEvents().
 */
class MatchBundleImporter
{
    public function import(array $bundle, string $hash): void
    {
        $match = null;

        Model::withoutEvents(function () use ($bundle, &$match): void {
            DB::transaction(function () use ($bundle, &$match): void {
                $match = $this->upsertMatchRow($bundle['match']);
                $this->replaceChildren($match, $bundle);

                // A limited deck snapshot that arrived (via its league
                // bundle) before this match did is waiting on its token.
                DB::table('limited_deck_snapshots')
                    ->where('match_token', $match->token)
                    ->whereNull('match_id')
                    ->update(['match_id' => $match->id]);
            });
        });

        $match->forceFill(['synced_hash' => $hash, 'synced_at' => now()])->saveQuietly();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function upsertMatchRow(array $data): MtgoMatch
    {
        $match = MtgoMatch::firstOrNew(['token' => $data['token']]);

        $match->forceFill([
            'mtgo_id' => $data['mtgo_id'],
            'format' => $data['format'],
            'match_type' => $data['match_type'],
            'result' => $data['result'],
            'outcome' => $data['outcome'],
            'games_won' => $data['games_won'],
            'games_lost' => $data['games_lost'],
            'started_at' => $data['started_at'],
            'ended_at' => $data['ended_at'],
            'state' => $data['state'],
            'notes' => $data['notes'],
            'imported' => $data['imported'],
            // Absent from bundles built before canonical_version 3.
            'manual' => $data['manual'] ?? false,
            'deck_version_id' => $this->resolveDeckVersionId($data['deck_mtgo_id'], $data['deck_version_signature']),
            'league_id' => $this->resolveLeagueId($data['league_client_id']),
            'tournament_event_id' => $data['tournament_mtgo_event_id'],
            'tournament_round' => $data['tournament_round'],
            // Bundles exclude submitted_at (per-device pipeline
            // bookkeeping), but a completed match arriving through sync was
            // already reported to the stats API by the device that played
            // it, and the server dedupes on (match_token, username) anyway.
            // Left null, the report pipeline would re-submit every imported
            // match and each submission's updated_at bump makes the row
            // look dirty until the next run self-heals it.
            'submitted_at' => $match->submitted_at
                ?? ($data['state'] === MatchState::Complete->value ? now() : null),
        ])->save();

        return $match;
    }

    /**
     * Deletes the old graph by match id, resolves players, archetypes and
     * the deck version first, then bulk inserts every child collection.
     * Whole-resource snapshots: no field-level merge.
     *
     * @param  array<string, mixed>  $bundle
     */
    private function replaceChildren(MtgoMatch $match, array $bundle): void
    {
        $oldGameIds = DB::table('games')->where('match_id', $match->id)->pluck('id');

        DB::table('game_player')->whereIn('game_id', $oldGameIds)->delete();
        DB::table('game_timelines')->whereIn('game_id', $oldGameIds)->delete();
        DB::table('card_game_stats')->whereIn('game_id', $oldGameIds)->delete();
        DB::table('match_archetypes')->where('mtgo_match_id', $match->id)->delete();
        DB::table('games')->where('match_id', $match->id)->delete();

        $now = now();

        $this->insertGames($match, $bundle['games'], $now);

        /** @var array<string, int> $gameIdsByMtgoId */
        $gameIdsByMtgoId = DB::table('games')->where('match_id', $match->id)->pluck('id', 'mtgo_id')->all();

        $playerIdsByUsername = $this->resolvePlayerIds($bundle['players'], $bundle['archetypes']);
        $instanceIdsByGameAndUsername = $this->resolveInstanceIds($bundle['timelines']);

        $this->insertPlayers($bundle['players'], $gameIdsByMtgoId, $playerIdsByUsername, $instanceIdsByGameAndUsername, $now);
        $this->insertTimelines($bundle['timelines'], $gameIdsByMtgoId, $now);
        $this->insertCardStats($bundle['card_stats'], $gameIdsByMtgoId, $match->deck_version_id, $now);
        $this->insertArchetypes($match, $bundle['archetypes'], $playerIdsByUsername, $now);
    }

    /**
     * @param  array<int, array<string, mixed>>  $games
     */
    private function insertGames(MtgoMatch $match, array $games, Carbon $now): void
    {
        $rows = collect($games)->map(fn (array $game) => [
            'match_id' => $match->id,
            'mtgo_id' => $game['mtgo_id'],
            'won' => $game['won'],
            'started_at' => $game['started_at'] === null ? null : Carbon::parse($game['started_at']),
            'ended_at' => $game['ended_at'] === null ? null : Carbon::parse($game['ended_at']),
            'turn_count' => $game['turn_count'],
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('games')->insert($chunk);
        }
    }

    /**
     * Players resolve by username, firstOrCreate before the bulk inserts: a
     * handful of rows, so model events are irrelevant under withoutEvents.
     * A username's is_player flag, when known from the archetypes section,
     * seeds a newly created row (never overwritten on an existing one):
     * the players section itself carries no is_player, and that is the
     * only bundle signal for which side is the account's own.
     *
     * @param  array<int, array<string, mixed>>  $players
     * @param  array<int, array<string, mixed>>  $archetypes
     * @return array<string, int>
     */
    private function resolvePlayerIds(array $players, array $archetypes): array
    {
        $isPlayerByUsername = collect($archetypes)
            ->filter(fn (array $row) => $row['player_username'] !== null)
            ->mapWithKeys(fn (array $row) => [$row['player_username'] => (bool) $row['player_is_player']]);

        $usernames = collect($players)->pluck('username')
            ->merge($isPlayerByUsername->keys())
            ->filter()
            ->unique();

        return $usernames->mapWithKeys(fn (string $username) => [
            $username => Player::firstOrCreate(
                ['username' => $username],
                ['is_player' => $isPlayerByUsername->get($username, false)],
            )->id,
        ])->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $players
     * @param  array<string, int>  $gameIdsByMtgoId
     * @param  array<string, int>  $playerIdsByUsername
     * @param  array<string, array<string, int>>  $instanceIdsByGameAndUsername
     */
    private function insertPlayers(array $players, array $gameIdsByMtgoId, array $playerIdsByUsername, array $instanceIdsByGameAndUsername, Carbon $now): void
    {
        $rows = collect($players)->map(fn (array $player) => [
            'game_id' => $gameIdsByMtgoId[$player['game']],
            'player_id' => $playerIdsByUsername[$player['username']],
            // instance_id is the MTGO log's per-player id, the same id
            // space as the Owner/Id fields inside game_timelines.content
            // (see CreateGames::upsertPlayerPivots, which sets it from the
            // very same Players[].Id the timeline carries). It is excluded
            // from the players section of the bundle, but ExtractGameHandData
            // / ParseOpeningHand read it to match a player against their
            // timeline snapshots for opening-hand and mulligan display, so
            // it must be recovered, not stubbed. Recovered here from the
            // bundle's own timeline content, which syncs verbatim. A
            // fallback to 0 only fires when this game has no timeline
            // content naming this username (an old match with no
            // timelines, or a stray username): hand parsing degrades for
            // that row exactly as it would for a local match recorded
            // without timelines.
            'instance_id' => $instanceIdsByGameAndUsername[$player['game']][$player['username']] ?? 0,
            'is_local' => $player['is_local'],
            'on_play' => $player['on_play'],
            'starting_hand_size' => $player['starting_hand_size'] ?? 7,
            'deck_json' => $player['deck_json'] === null ? null : json_encode($player['deck_json']),
            'mulligan_count' => $player['mulligan_count'],
            'dice_roll' => $player['dice_roll'],
            // Absent from bundles built before canonical_version 3.
            'opening_hand_json' => ($player['opening_hand_json'] ?? null) === null ? null : json_encode($player['opening_hand_json']),
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('game_player')->insert($chunk);
        }
    }

    /**
     * Recovers each player's real MTGO instance id per game from the
     * bundle's own timeline content, rather than inventing one: the
     * `Players` array inside `game_timelines.content` (synced verbatim)
     * carries the same `{Name, Id}` pairs CreateGames reads to set
     * game_player.instance_id in the first place (see
     * Games\ShowController, which performs the identical Name-based
     * correlation to annotate the timeline for display). A game with
     * several snapshots can repeat the same player several times; later
     * snapshots win, though the id is stable for the whole game in
     * practice.
     *
     * @param  array<int, array<string, mixed>>  $timelines
     * @return array<string, array<string, int>> game mtgo_id => (username => instance_id)
     */
    private function resolveInstanceIds(array $timelines): array
    {
        $map = [];

        foreach ($timelines as $timeline) {
            $players = $timeline['content']['Players'] ?? null;

            if (! is_array($players)) {
                continue;
            }

            foreach ($players as $player) {
                $name = $player['Name'] ?? null;
                $id = $player['Id'] ?? null;

                if ($name === null || $id === null) {
                    continue;
                }

                $map[$timeline['game']][$name] = (int) $id;
            }
        }

        return $map;
    }

    /**
     * @param  array<int, array<string, mixed>>  $timelines
     * @param  array<string, int>  $gameIdsByMtgoId
     */
    private function insertTimelines(array $timelines, array $gameIdsByMtgoId, Carbon $now): void
    {
        $rows = collect($timelines)->map(fn (array $timeline) => [
            'game_id' => $gameIdsByMtgoId[$timeline['game']],
            'timestamp' => Carbon::parse($timeline['timestamp']),
            'content' => json_encode($timeline['content']),
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('game_timelines')->insert($chunk);
        }
    }

    /**
     * card_game_stats.deck_version_id is NOT NULL and absent from the
     * bundle: every row is filled from the match's own resolved
     * deck_version_id. The live pipeline (ComputeCardGameStats) never
     * writes card stats for a deckless match, so a bundle built from real
     * data never carries stats without a resolvable deck_version_id; if
     * one somehow does (only reachable in tests, since the column itself
     * forbids it in the database this bundle came from), those rows are
     * dropped rather than raising a constraint violation, since there is
     * nothing valid to fill the column with.
     *
     * @param  array<int, array<string, mixed>>  $cardStats
     * @param  array<string, int>  $gameIdsByMtgoId
     */
    private function insertCardStats(array $cardStats, array $gameIdsByMtgoId, ?int $deckVersionId, Carbon $now): void
    {
        if ($deckVersionId === null || $cardStats === []) {
            return;
        }

        $rows = collect($cardStats)->map(function (array $stat) use ($gameIdsByMtgoId, $deckVersionId, $now) {
            $row = [
                'game_id' => $gameIdsByMtgoId[$stat['game']],
                'deck_version_id' => $deckVersionId,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            foreach ($stat as $column => $value) {
                if ($column !== 'game') {
                    $row[$column] = $value;
                }
            }

            return $row;
        })->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('card_game_stats')->insert($chunk);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $archetypes
     * @param  array<string, int>  $playerIdsByUsername
     */
    private function insertArchetypes(MtgoMatch $match, array $archetypes, array $playerIdsByUsername, Carbon $now): void
    {
        $rows = collect($archetypes)
            ->filter(fn (array $row) => $row['uuid'] !== null && $row['player_username'] !== null)
            ->map(fn (array $row) => [
                'archetype_id' => LocalArchetypeId::for($row['uuid']),
                'mtgo_match_id' => $match->id,
                'player_id' => $playerIdsByUsername[$row['player_username']],
                'confidence' => $row['confidence'],
                'manual' => $row['manual'],
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('match_archetypes')->insert($chunk);
        }
    }

    /**
     * Deck by mtgo_id, version by (deck_id, signature). A missing deck
     * (never synced to this device, a free-tier account may not have
     * everything) resolves to null rather than inventing one. A known deck
     * missing this exact version (the deck bundle normally creates it
     * first, per Task 7's pull order) gets the version stubbed in with
     * modified_at set to now: the true value is only carried on the deck
     * bundle, not here, and "now" is the least-wrong stand-in for a version
     * this device has otherwise never seen.
     */
    private function resolveDeckVersionId(?string $deckMtgoId, ?string $signature): ?int
    {
        if ($deckMtgoId === null || $signature === null) {
            return null;
        }

        $deck = Deck::withTrashed()->where('mtgo_id', $deckMtgoId)->first();

        if ($deck === null) {
            return null;
        }

        return DeckVersion::firstOrCreate(
            ['deck_id' => $deck->id, 'signature' => $signature],
            ['modified_at' => now()],
        )->id;
    }

    /**
     * league_client_id is "{token}_{Ymd\THis\Z}" (LeagueClientId::for). The
     * timestamp suffix has a fixed, unambiguous shape, so it is split off
     * with a regex rather than a naive search for the last underscore: a
     * token could itself contain one.
     */
    private function resolveLeagueId(?string $clientId): ?int
    {
        if ($clientId === null) {
            return null;
        }

        if (preg_match('/^(?<token>.+)_(?<timestamp>\d{8}T\d{6}Z)$/', $clientId, $parts) !== 1) {
            return null;
        }

        $startedAt = Carbon::createFromFormat('Ymd\THis\Z', $parts['timestamp'], 'UTC');

        if ($startedAt === false) {
            return null;
        }

        return League::withTrashed()
            ->where('token', $parts['token'])
            ->where('started_at', $startedAt)
            ->value('id');
    }
}
