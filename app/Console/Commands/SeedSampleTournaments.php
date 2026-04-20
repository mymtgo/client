<?php

namespace App\Console\Commands;

use App\Enums\TournamentState;
use App\Enums\TournamentStructure;
use App\Enums\TournamentTimelineEventType;
use App\Enums\TournamentType;
use App\Models\Deck;
use App\Models\MtgoMatch;
use App\Models\Tournament;
use App\Models\TournamentStanding;
use App\Models\TournamentTimelineEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SeedSampleTournaments extends Command
{
    protected $signature = 'tournaments:seed-sample {--deck= : Deck name to source matches from (default: Eldrazi ramp)}';

    protected $description = 'Seed two dev-sample tournaments using real match data from a given deck.';

    private const LOCAL_LOGIN_ID = 964394;

    private const MARKER_PREFIX = 'DEV SAMPLE:';

    public function handle(): int
    {
        $deckName = $this->option('deck') ?? 'Eldrazi ramp';

        $deck = Deck::query()->where('name', $deckName)->first();

        if (! $deck) {
            $this->error("Deck '{$deckName}' not found.");

            return self::FAILURE;
        }

        $this->cleanupPriorSamples();

        $matches = MtgoMatch::query()
            ->whereIn('deck_version_id', $deck->versions()->pluck('id'))
            ->whereNull('tournament_id')
            ->where('state', 'complete')
            ->orderByDesc('started_at')
            ->limit(13)
            ->get();

        if ($matches->count() < 13) {
            $this->error("Need 13 unlinked completed matches on '{$deckName}', found {$matches->count()}.");

            return self::FAILURE;
        }

        $sourceStandings = $this->loadSourceStandings();

        $topEightMatches = $matches->slice(0, 7)->values();
        $droppedMatches = $matches->slice(7, 6)->values();

        $this->seedTopEight($topEightMatches, $sourceStandings);
        $this->seedDropped($droppedMatches, $sourceStandings);

        $this->info('Seeded 2 sample tournaments with full standings.');

        return self::SUCCESS;
    }

    private function cleanupPriorSamples(): void
    {
        $priorIds = Tournament::query()
            ->where('name', 'like', self::MARKER_PREFIX.'%')
            ->pluck('id');

        if ($priorIds->isEmpty()) {
            return;
        }

        MtgoMatch::query()
            ->whereIn('tournament_id', $priorIds)
            ->update([
                'tournament_id' => null,
                'tournament_round' => null,
                'participant_login_ids' => null,
            ]);

        Tournament::query()->whereIn('id', $priorIds)->forceDelete();
    }

    /**
     * Pull real standings rows from the legacy challenge_standings table
     * (if it still exists) to use as seed source. Returns a single
     * flat collection of ~45 player rows sorted by rank.
     *
     * @return Collection<int, object>
     */
    private function loadSourceStandings(): Collection
    {
        if (! Schema::hasTable('challenge_standings')) {
            return collect();
        }

        $best = DB::table('challenge_standings')
            ->select('challenge_id', DB::raw('COUNT(*) as n'))
            ->groupBy('challenge_id')
            ->orderByDesc('n')
            ->first();

        if (! $best) {
            return collect();
        }

        $maxRound = (int) DB::table('challenge_standings')
            ->where('challenge_id', $best->challenge_id)
            ->max('round');

        return collect(DB::table('challenge_standings')
            ->where('challenge_id', $best->challenge_id)
            ->where('round', $maxRound)
            ->where('login_id', '!=', self::LOCAL_LOGIN_ID)
            ->orderBy('rank')
            ->get());
    }

    private function seedTopEight(Collection $matches, Collection $source): void
    {
        $startedAt = Carbon::now()->subDays(5)->setTime(14, 0);
        $totalRounds = 7;
        $localFinalRank = 6;
        $playerCount = $source->count() + 1;

        $tournament = Tournament::create([
            'token' => (string) Str::uuid(),
            'event_id' => 99000001,
            'name' => self::MARKER_PREFIX.' Modern Challenge 32 (Top 8)',
            'category' => 'Challenge',
            'format' => 'Modern',
            'description' => 'Modern',
            'type' => TournamentType::Constructed,
            'tournament_structure' => TournamentStructure::Swiss,
            'state' => TournamentState::Completed,
            'current_round' => $totalRounds,
            'max_rounds' => $totalRounds,
            'player_count' => $playerCount,
            'min_players' => 32,
            'max_players' => 256,
            'started_at' => $startedAt,
            'ended_at' => $startedAt->copy()->addHours(5),
            'participated' => true,
        ]);

        $this->linkMatches($tournament, $matches, opponentOffset: 1_000_000);

        $this->seedStandingsProgression(
            tournament: $tournament,
            source: $source,
            totalRounds: $totalRounds,
            finalRank: $localFinalRank,
            finalWins: 5,
            finalLosses: 2,
            finalOmw: 0.58,
            finalGw: 0.62,
        );

        TournamentTimelineEvent::create([
            'tournament_id' => $tournament->id,
            'round' => null,
            'event_type' => TournamentTimelineEventType::StateChanged,
            'login_id' => null,
            'username' => null,
            'payload' => ['state' => TournamentState::Completed->value],
            'occurred_at' => $tournament->ended_at,
        ]);
    }

    private function seedDropped(Collection $matches, Collection $source): void
    {
        $startedAt = Carbon::now()->subDays(2)->setTime(14, 0);
        $totalRounds = 7;
        $localDropRound = 6;
        $playerCount = $source->count() + 1;
        $localFinalRank = max(2, $playerCount - 4);

        $tournament = Tournament::create([
            'token' => (string) Str::uuid(),
            'event_id' => 99000002,
            'name' => self::MARKER_PREFIX.' Modern Challenge 32 (Dropped Round 6)',
            'category' => 'Challenge',
            'format' => 'Modern',
            'description' => 'Modern',
            'type' => TournamentType::Constructed,
            'tournament_structure' => TournamentStructure::Swiss,
            'state' => TournamentState::Completed,
            'current_round' => $totalRounds,
            'max_rounds' => $totalRounds,
            'player_count' => $playerCount,
            'min_players' => 32,
            'max_players' => 256,
            'started_at' => $startedAt,
            'ended_at' => $startedAt->copy()->addHours(5),
            'participated' => true,
        ]);

        $this->linkMatches($tournament, $matches, opponentOffset: 2_000_000);

        $this->seedStandingsProgression(
            tournament: $tournament,
            source: $source,
            totalRounds: $localDropRound,
            finalRank: $localFinalRank,
            finalWins: 2,
            finalLosses: 4,
            finalOmw: 0.45,
            finalGw: 0.41,
        );

        TournamentTimelineEvent::create([
            'tournament_id' => $tournament->id,
            'round' => $localDropRound,
            'event_type' => TournamentTimelineEventType::PlayerEliminated,
            'login_id' => self::LOCAL_LOGIN_ID,
            'username' => null,
            'payload' => ['reason' => 'Drop'],
            'occurred_at' => $tournament->started_at->copy()->addHours(4),
        ]);

        TournamentTimelineEvent::create([
            'tournament_id' => $tournament->id,
            'round' => null,
            'event_type' => TournamentTimelineEventType::StateChanged,
            'login_id' => null,
            'username' => null,
            'payload' => ['state' => TournamentState::Completed->value],
            'occurred_at' => $tournament->ended_at,
        ]);
    }

    /**
     * Populate all rounds of a tournament with standings, copied from the
     * source collection (~45 real players) with the local user inserted
     * at a rank that interpolates from mid-pack toward their final rank.
     *
     * Ranks ≥ local's slot are shifted up by 1 to make room; wins/losses
     * interpolate linearly so each round shows a plausible progression.
     *
     * @param  Collection<int, object>  $source
     */
    private function seedStandingsProgression(
        Tournament $tournament,
        Collection $source,
        int $totalRounds,
        int $finalRank,
        int $finalWins,
        int $finalLosses,
        float $finalOmw,
        float $finalGw,
    ): void {
        $playerCount = $source->count() + 1;
        $midRank = (int) round($playerCount / 2);

        foreach (range(1, $totalRounds) as $round) {
            $progress = $round / $totalRounds;

            $localRank = (int) round($midRank + ($finalRank - $midRank) * $progress);
            $localRank = max(1, min($playerCount, $localRank));

            $localWins = (int) round($finalWins * $progress);
            $localLosses = (int) round($finalLosses * $progress);
            $localPoints = $localWins * 3;
            $localOmw = $round > 1 ? $finalOmw : null;
            $localGw = $round > 1 ? $finalGw : null;

            foreach ($source as $src) {
                $shiftedRank = $src->rank >= $localRank ? $src->rank + 1 : $src->rank;

                if ($shiftedRank > $playerCount) {
                    continue;
                }

                $roundWins = (int) round(($src->wins ?? 0) * $progress);
                $roundLosses = (int) round(($src->losses ?? 0) * $progress);
                $roundDraws = (int) round(($src->draws ?? 0) * $progress);

                TournamentStanding::create([
                    'tournament_id' => $tournament->id,
                    'round' => $round,
                    'login_id' => $src->login_id,
                    'username' => $src->username,
                    'rank' => $shiftedRank,
                    'points' => ($roundWins * 3) + $roundDraws,
                    'wins' => $roundWins,
                    'losses' => $roundLosses,
                    'draws' => $roundDraws,
                    'opponent_match_win_pct' => $round > 1 ? $src->opponent_match_win_pct : null,
                    'game_win_pct' => $round > 1 ? $src->game_win_pct : null,
                    'is_local' => false,
                ]);
            }

            TournamentStanding::create([
                'tournament_id' => $tournament->id,
                'round' => $round,
                'login_id' => self::LOCAL_LOGIN_ID,
                'username' => null,
                'rank' => $localRank,
                'points' => $localPoints,
                'wins' => $localWins,
                'losses' => $localLosses,
                'draws' => 0,
                'opponent_match_win_pct' => $localOmw,
                'game_win_pct' => $localGw,
                'is_local' => true,
            ]);
        }
    }

    private function linkMatches(Tournament $tournament, Collection $matches, int $opponentOffset): void
    {
        foreach ($matches as $index => $match) {
            $round = $index + 1;

            $match->update([
                'tournament_id' => $tournament->id,
                'tournament_round' => $round,
                'participant_login_ids' => [self::LOCAL_LOGIN_ID, $opponentOffset + $round],
            ]);
        }
    }
}
