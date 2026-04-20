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

        $topEightMatches = $matches->slice(0, 7)->values();
        $droppedMatches = $matches->slice(7, 6)->values();

        $this->seedTopEight($topEightMatches);
        $this->seedDropped($droppedMatches);

        $this->info('Seeded 2 sample tournaments.');

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

    private function seedTopEight(Collection $matches): void
    {
        $startedAt = Carbon::now()->subDays(5)->setTime(14, 0);

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
            'current_round' => 7,
            'max_rounds' => 7,
            'player_count' => 128,
            'min_players' => 32,
            'max_players' => 256,
            'started_at' => $startedAt,
            'ended_at' => $startedAt->copy()->addHours(5),
            'participated' => true,
        ]);

        $record = $this->linkMatches($tournament, $matches, opponentOffset: 1_000_000);

        TournamentStanding::create([
            'tournament_id' => $tournament->id,
            'round' => 7,
            'login_id' => self::LOCAL_LOGIN_ID,
            'username' => null,
            'rank' => 6,
            'points' => ($record['wins'] * 3) + $record['draws'],
            'wins' => $record['wins'],
            'losses' => $record['losses'],
            'draws' => $record['draws'],
            'opponent_match_win_pct' => 0.58,
            'game_win_pct' => 0.62,
            'is_local' => true,
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

    private function seedDropped(Collection $matches): void
    {
        $startedAt = Carbon::now()->subDays(2)->setTime(14, 0);

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
            'current_round' => 7,
            'max_rounds' => 7,
            'player_count' => 96,
            'min_players' => 32,
            'max_players' => 256,
            'started_at' => $startedAt,
            'ended_at' => $startedAt->copy()->addHours(5),
            'participated' => true,
        ]);

        $record = $this->linkMatches($tournament, $matches, opponentOffset: 2_000_000);

        TournamentStanding::create([
            'tournament_id' => $tournament->id,
            'round' => 6,
            'login_id' => self::LOCAL_LOGIN_ID,
            'username' => null,
            'rank' => 78,
            'points' => ($record['wins'] * 3) + $record['draws'],
            'wins' => $record['wins'],
            'losses' => $record['losses'],
            'draws' => $record['draws'],
            'opponent_match_win_pct' => 0.45,
            'game_win_pct' => 0.41,
            'is_local' => true,
        ]);

        TournamentTimelineEvent::create([
            'tournament_id' => $tournament->id,
            'round' => 6,
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
     * @return array{wins: int, losses: int, draws: int}
     */
    private function linkMatches(Tournament $tournament, Collection $matches, int $opponentOffset): array
    {
        $wins = 0;
        $losses = 0;
        $draws = 0;

        foreach ($matches as $index => $match) {
            $round = $index + 1;

            $match->update([
                'tournament_id' => $tournament->id,
                'tournament_round' => $round,
                'participant_login_ids' => [self::LOCAL_LOGIN_ID, $opponentOffset + $round],
            ]);

            match ($match->outcome?->value ?? 'unknown') {
                'win' => $wins++,
                'loss' => $losses++,
                'draw' => $draws++,
                default => null,
            };
        }

        return ['wins' => $wins, 'losses' => $losses, 'draws' => $draws];
    }
}
