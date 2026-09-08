<?php

namespace App\Actions\Matches;

use App\Actions\Archetypes\ResolveMergedArchetype;
use App\Actions\Leagues\CompleteLeague;
use App\Enums\MatchState;
use App\Facades\AppSettings;
use App\Facades\Mtgo;
use App\Jobs\ComputeCardGameStats;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\League;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateManualMatch
{
    /**
     * Write a user-entered match straight into Complete state.
     *
     * Mirrors ImportMatches: no log events, no observer transition, so the
     * follow-ups (card stats, league completion) are called explicitly. The
     * match is flagged manual and is never submitted to the API.
     *
     * @param  array{
     *     deck_id: int,
     *     opponent_name: string,
     *     archetype_id?: int|null,
     *     league_id?: int|null,
     *     started_at: string,
     *     ended_at: string,
     *     games: list<array{won: bool|string|int, on_play: bool|string|int, turns?: int|string|null}>
     * }  $data
     */
    public static function run(array $data): MtgoMatch
    {
        $deck = Deck::query()->findOrFail($data['deck_id']);
        $league = ! empty($data['league_id']) ? League::query()->findOrFail($data['league_id']) : null;
        $deckVersion = $league?->deck_version_id
            ? DeckVersion::query()->findOrFail($league->deck_version_id)
            : $deck->latestVersion()->firstOrFail();

        $games = collect($data['games'])->map(fn (array $game) => [
            'won' => filter_var($game['won'], FILTER_VALIDATE_BOOLEAN),
            'on_play' => filter_var($game['on_play'], FILTER_VALIDATE_BOOLEAN),
            'turns' => isset($game['turns']) && $game['turns'] !== '' ? (int) $game['turns'] : null,
        ])->values();

        $wins = $games->where('won', true)->count();
        $losses = $games->count() - $wins;
        // The form posts zone-less local times (datetime-local). Read them in
        // the user's system zone and store UTC, like every other timestamp.
        $systemTz = AppSettings::systemTimezone();
        $startedAt = Carbon::parse($data['started_at'], $systemTz)->utc();
        $endedAt = Carbon::parse($data['ended_at'], $systemTz)->utc();

        $match = DB::transaction(function () use ($deck, $league, $deckVersion, $games, $wins, $losses, $startedAt, $endedAt, $data): MtgoMatch {
            $local = Player::firstOrCreate(['username' => Mtgo::getUsername() ?? 'You']);
            $opponent = Player::firstOrCreate(['username' => trim($data['opponent_name'])]);

            $match = MtgoMatch::create([
                'token' => Str::uuid()->toString(),
                'mtgo_id' => 'manual_'.Str::random(24),
                'deck_version_id' => $deckVersion->id,
                'format' => $deck->format,
                'match_type' => $league ? 'League' : 'Constructed',
                'games_won' => $wins,
                'games_lost' => $losses,
                'started_at' => $startedAt,
                'ended_at' => $endedAt,
                'state' => MatchState::Complete,
                'outcome' => MtgoMatch::determineOutcome($wins, $losses),
                'manual' => true,
                'league_id' => $league?->id,
            ]);

            self::createGames($match, $games, $local, $opponent, $startedAt, $endedAt);

            if (! empty($data['archetype_id'])) {
                $resolved = ResolveMergedArchetype::run((int) $data['archetype_id'], null);
                MatchArchetype::create([
                    'mtgo_match_id' => $match->id,
                    'archetype_id' => $resolved['archetype_id'],
                    'archetype_deck_id' => $resolved['archetype_deck_id'],
                    'player_id' => $opponent->id,
                    'confidence' => 1.0,
                    'manual' => true,
                ]);
            }

            if ($deck->archetype_id) {
                $resolved = ResolveMergedArchetype::run($deck->archetype_id, null);
                MatchArchetype::create([
                    'mtgo_match_id' => $match->id,
                    'archetype_id' => $resolved['archetype_id'],
                    'archetype_deck_id' => $resolved['archetype_deck_id'],
                    'player_id' => $local->id,
                    'confidence' => 1.0,
                    'manual' => false,
                ]);
            }

            return $match;
        });

        ComputeCardGameStats::dispatchSync($match->id);

        if ($league) {
            CompleteLeague::runIfFinished($league->fresh());
        }

        return $match;
    }

    /**
     * Games get evenly spaced timestamps inside the match window so every
     * reader that orders by games.started_at sees them in entry order.
     *
     * @param  Collection<int, array{won: bool, on_play: bool, turns: int|null}>  $games
     */
    private static function createGames(MtgoMatch $match, Collection $games, Player $local, Player $opponent, Carbon $startedAt, Carbon $endedAt): void
    {
        $sliceSeconds = intdiv(max(0, (int) $startedAt->diffInSeconds($endedAt, true)), max(1, $games->count()));

        foreach ($games as $index => $gameData) {
            $game = Game::create([
                'match_id' => $match->id,
                'mtgo_id' => Str::uuid()->toString(),
                'won' => $gameData['won'],
                'turn_count' => $gameData['turns'],
                'started_at' => $startedAt->copy()->addSeconds($sliceSeconds * $index),
                'ended_at' => $startedAt->copy()->addSeconds($sliceSeconds * ($index + 1)),
            ]);

            $game->players()->attach($local->id, [
                'is_local' => true,
                'on_play' => $gameData['on_play'],
                'starting_hand_size' => 7,
                'instance_id' => 0,
                'deck_json' => [],
                'mulligan_count' => 0,
            ]);

            $game->players()->attach($opponent->id, [
                'is_local' => false,
                'on_play' => ! $gameData['on_play'],
                'starting_hand_size' => 7,
                'instance_id' => 1,
                'deck_json' => [],
                'mulligan_count' => 0,
            ]);
        }
    }
}
