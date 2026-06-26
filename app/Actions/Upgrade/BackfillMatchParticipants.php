<?php

namespace App\Actions\Upgrade;

use App\Models\Account;
use App\Models\Game;
use App\Models\GameDeck;
use App\Models\MtgoMatch;
use App\Models\Opponent;
use Illuminate\Support\Facades\DB;

class BackfillMatchParticipants
{
    /**
     * Backfill new-schema participant columns for a single match from legacy
     * `game_player` + `players` rows.
     *
     * Every write is guarded so the method is fully idempotent: re-running
     * against an already-populated match is a no-op.
     */
    public static function run(MtgoMatch $match): void
    {
        $games = $match->games;

        if ($games->isEmpty()) {
            return;
        }

        $gameIds = $games->pluck('id');

        // Load all legacy game_player rows for this match's games, joined to players.username.
        $pivotRows = DB::table('game_player')
            ->join('players', 'players.id', '=', 'game_player.player_id')
            ->whereIn('game_player.game_id', $gameIds)
            ->select([
                'game_player.game_id',
                'game_player.is_local',
                'game_player.on_play',
                'game_player.instance_id',
                'game_player.dice_roll',
                'game_player.mulligan_count',
                'game_player.deck_json',
                'players.username',
            ])
            ->get();

        if ($pivotRows->isEmpty()) {
            return;
        }

        // Index by game_id, keyed by is_local flag for quick lookup.
        $byGame = $pivotRows->groupBy('game_id');

        // Resolve account_id (once, from the first local row we find).
        if ($match->account_id === null) {
            $localRow = $pivotRows->firstWhere('is_local', true);

            if ($localRow !== null) {
                $accountId = Account::where('username', $localRow->username)->first()?->id
                    ?? Account::currentId();

                if ($accountId !== null) {
                    $match->account_id = $accountId;
                    $match->save();
                }
            }
        }

        // Resolve opponent_id (once, from the first opponent row we find).
        if ($match->opponent_id === null) {
            $opponentRow = $pivotRows->firstWhere('is_local', false);

            if ($opponentRow !== null) {
                $opponent = Opponent::firstOrCreate(['username' => $opponentRow->username]);
                $match->opponent_id = $opponent->id;
                $match->save();
            }
        }

        // Per-game: scalars + game_decks.
        foreach ($games as $game) {
            $rows = $byGame->get($game->id);

            if (! $rows || $rows->isEmpty()) {
                continue;
            }

            /** @var \stdClass|null $local */
            $local = $rows->firstWhere('is_local', true);
            /** @var \stdClass|null $opponent */
            $opponent = $rows->firstWhere('is_local', false);

            self::fillGameScalars($game, $local, $opponent);

            self::upsertGameDecks($game->id, $local, $opponent);
        }
    }

    /**
     * Populate game scalar columns that are currently null.
     *
     * Rules:
     *  - mulligan_count 0 is valid and must be written.
     *  - dice_roll 0 is NOT a real roll and must be skipped (stays null).
     *
     * @param  \stdClass|null  $local
     * @param  \stdClass|null  $opponent
     */
    private static function fillGameScalars(Game $game, ?object $local, ?object $opponent): void
    {
        $updates = [];

        if ($local !== null) {
            if ($game->local_on_play === null) {
                $updates['local_on_play'] = (bool) $local->on_play;
            }

            if ($game->local_mulligans === null && $local->mulligan_count !== null) {
                $updates['local_mulligans'] = (int) $local->mulligan_count;
            }

            if ($game->local_dice === null && ! empty($local->dice_roll)) {
                $updates['local_dice'] = (int) $local->dice_roll;
            }

            if ($game->local_instance === null) {
                $updates['local_instance'] = (int) $local->instance_id;
            }
        }

        if ($opponent !== null) {
            if ($game->opp_mulligans === null && $opponent->mulligan_count !== null) {
                $updates['opp_mulligans'] = (int) $opponent->mulligan_count;
            }

            if ($game->opp_dice === null && ! empty($opponent->dice_roll)) {
                $updates['opp_dice'] = (int) $opponent->dice_roll;
            }

            if ($game->opp_instance === null) {
                $updates['opp_instance'] = (int) $opponent->instance_id;
            }
        }

        if (! empty($updates)) {
            $game->fill($updates)->save();
        }
    }

    /**
     * Upsert game_decks rows (idempotent via updateOrCreate).
     *
     * deck_json is stored as a JSON string in the legacy pivot; decode it to
     * an array before writing (GameDeck casts deck_json as array).
     * Default to an empty array when deck_json is null.
     *
     * @param  \stdClass|null  $local
     * @param  \stdClass|null  $opponent
     */
    private static function upsertGameDecks(int $gameId, ?object $local, ?object $opponent): void
    {
        $localDeck = $local !== null
            ? (is_string($local->deck_json) ? json_decode($local->deck_json, true) : ($local->deck_json ?? []))
            : [];

        $opponentDeck = $opponent !== null
            ? (is_string($opponent->deck_json) ? json_decode($opponent->deck_json, true) : ($opponent->deck_json ?? []))
            : [];

        GameDeck::updateOrCreate(
            ['game_id' => $gameId, 'is_opponent' => false],
            ['deck_json' => $localDeck ?? []],
        );

        GameDeck::updateOrCreate(
            ['game_id' => $gameId, 'is_opponent' => true],
            ['deck_json' => $opponentDeck ?? []],
        );
    }
}
