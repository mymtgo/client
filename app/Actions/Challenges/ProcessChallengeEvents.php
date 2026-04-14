<?php

namespace App\Actions\Challenges;

use App\Actions\Util\ExtractJson;
use App\Enums\ChallengeTimelineEventType;
use App\Enums\TournamentState;
use App\Enums\TournamentStructure;
use App\Models\Challenge;
use App\Models\ChallengeStanding;
use App\Models\ChallengeTimelineEvent;
use App\Models\LogEvent;
use App\Models\Player;
use Illuminate\Support\Facades\Log;

class ProcessChallengeEvents
{
    public static function run(): void
    {
        $eventTypes = [
            'challenge_sync',
            'challenge_state_changed',
            'challenge_round_result',
            'challenge_player_eliminated',
            'challenge_ended',
            'challenge_match_state_changed',
        ];

        $events = LogEvent::whereIn('event_type', $eventTypes)
            ->whereNull('processed_at')
            ->orderBy('logged_at')
            ->get();

        foreach ($events as $event) {
            match ($event->event_type) {
                'challenge_sync' => self::processSync($event),
                'challenge_state_changed' => self::processStateChanged($event),
                'challenge_round_result' => self::processRoundResult($event),
                'challenge_player_eliminated' => self::processElimination($event),
                'challenge_ended' => self::processEnded($event),
                'challenge_match_state_changed' => self::processMatchStateChanged($event),
                default => null,
            };

            $event->update(['processed_at' => now()]);
        }
    }

    private static function processSync(LogEvent $event): void
    {
        $json = ExtractJson::run($event->raw_text)->first();
        if (! is_array($json) || ! isset($json['EventToken'])) {
            return;
        }

        $token = $json['EventToken'];
        $tournamentData = $json['PremiereEventSyncData'] ?? [];

        $challenge = Challenge::updateOrCreate(
            ['token' => $token],
            array_filter([
                'name' => $json['Description'] ?? null,
                'description' => isset($json['FormatDescription']) ? StripBbCode::run($json['FormatDescription']) : null,
                'tournament_structure' => isset($tournamentData['TournamentStructureCd'])
                    ? TournamentStructure::fromMtgoCode($tournamentData['TournamentStructureCd'])
                    : null,
                'max_rounds' => $tournamentData['NumberOfRounds'] ?? null,
                'min_players' => $tournamentData['MinPlayers'] ?? null,
                'max_players' => $tournamentData['MaxPlayers'] ?? null,
                'player_count' => count($json['Players'] ?? []),
            ], fn ($v) => $v !== null),
        );

        foreach ($json['Players'] ?? [] as $player) {
            if (isset($player['LoginID'], $player['PlayerName'])) {
                Player::updateOrCreate(
                    ['login_id' => $player['LoginID']],
                    ['username' => $player['PlayerName']],
                );
            }
        }

        Log::channel('pipeline')->info("ProcessChallengeEvents: synced challenge #{$challenge->id}", [
            'token' => $token,
            'name' => $challenge->name,
            'players' => count($json['Players'] ?? []),
        ]);
    }

    private static function processStateChanged(LogEvent $event): void
    {
        $token = $event->match_token;
        $text = $event->raw_text;

        $toState = null;
        if (preg_match('/to (\S+)\)/', $text, $m)) {
            $toState = TournamentState::fromMtgoState($m[1]);
        }

        if (! $toState) {
            return;
        }

        $updates = ['state' => $toState];

        if ($toState === TournamentState::RoundInProgress) {
            $existing = Challenge::where('token', $token)->first();
            $updates['current_round'] = ($existing->current_round ?? 0) + 1;
        }

        if ($toState === TournamentState::Completed) {
            $updates['ended_at'] = $event->logged_at;
        }

        if ($toState !== TournamentState::AwaitingPlayers) {
            $existing = $existing ?? Challenge::where('token', $token)->first();
            if ($existing && ! $existing->started_at) {
                $updates['started_at'] = $event->logged_at;
            }
        }

        $challenge = Challenge::updateOrCreate(
            ['token' => $token],
            $updates,
        );

        ChallengeTimelineEvent::create([
            'challenge_id' => $challenge->id,
            'event_type' => ChallengeTimelineEventType::StateChanged,
            'payload' => ['to_state' => $toState->value],
            'occurred_at' => $event->logged_at,
        ]);
    }

    private static function processRoundResult(LogEvent $event): void
    {
        $json = ExtractJson::run($event->raw_text)->first();
        if (! is_array($json) || ! isset($json['Token'], $json['Round'], $json['Results'])) {
            return;
        }

        $challenge = Challenge::where('token', $json['Token'])->first();
        if (! $challenge) {
            return;
        }

        $round = (int) $json['Round'];
        $challenge->update([
            'current_round' => $round,
            'player_count' => count($json['Results']),
        ]);

        $loginIds = collect($json['Results'])->pluck('LoginID')->all();
        $usernameMap = Player::whereIn('login_id', $loginIds)
            ->pluck('username', 'login_id')
            ->all();

        foreach ($json['Results'] as $result) {
            $loginId = (int) $result['LoginID'];

            $records = collect($result['OpponentResults'] ?? [])
                ->sortBy('Round')
                ->map(fn ($r) => $r['Win'].'-'.$r['Loss'])
                ->implode(', ');

            ChallengeStanding::updateOrCreate(
                [
                    'challenge_id' => $challenge->id,
                    'round' => $round,
                    'login_id' => $loginId,
                ],
                [
                    'username' => $usernameMap[$loginId] ?? null,
                    'rank' => (int) $result['Rank'],
                    'points' => (int) $result['Points'],
                    'match_record' => $records,
                    'opponent_match_win_pct' => isset($result['OpponentMatchWinPercentage'])
                        ? (float) $result['OpponentMatchWinPercentage']
                        : null,
                    'game_win_pct' => isset($result['GameWinPercentage'])
                        ? (float) $result['GameWinPercentage']
                        : null,
                ],
            );
        }

        ChallengeTimelineEvent::create([
            'challenge_id' => $challenge->id,
            'round' => $round,
            'event_type' => ChallengeTimelineEventType::RoundResult,
            'payload' => ['player_count' => count($json['Results'])],
            'occurred_at' => $event->logged_at,
        ]);

        Log::channel('pipeline')->info("ProcessChallengeEvents: round {$round} results for challenge #{$challenge->id}", [
            'players' => count($json['Results']),
        ]);
    }

    private static function processElimination(LogEvent $event): void
    {
        $json = ExtractJson::run($event->raw_text)->first();
        if (! is_array($json) || ! isset($json['Token'], $json['LoginID'])) {
            return;
        }

        $challenge = Challenge::where('token', $json['Token'])->first();
        if (! $challenge) {
            return;
        }

        $loginId = (int) $json['LoginID'];
        $username = Player::where('login_id', $loginId)->value('username');

        ChallengeTimelineEvent::create([
            'challenge_id' => $challenge->id,
            'round' => $challenge->current_round,
            'event_type' => ChallengeTimelineEventType::PlayerEliminated,
            'login_id' => $loginId,
            'username' => $username,
            'payload' => ['reason' => $json['Reason'] ?? null],
            'occurred_at' => $event->logged_at,
        ]);
    }

    private static function processEnded(LogEvent $event): void
    {
        $json = ExtractJson::run($event->raw_text)->first();
        if (! is_array($json) || ! isset($json['Token'])) {
            return;
        }

        $challenge = Challenge::where('token', $json['Token'])->first();
        if (! $challenge) {
            return;
        }

        $challenge->update([
            'state' => TournamentState::Completed,
            'ended_at' => isset($json['EndDate']) ? $json['EndDate'] : $event->logged_at,
        ]);

        ChallengeTimelineEvent::create([
            'challenge_id' => $challenge->id,
            'event_type' => ChallengeTimelineEventType::StateChanged,
            'payload' => ['to_state' => TournamentState::Completed->value],
            'occurred_at' => $event->logged_at,
        ]);
    }

    private static function processMatchStateChanged(LogEvent $event): void
    {
        // Tournament match events reference match tokens, not tournament tokens.
        // Low-priority feed events — skip if no challenge mapping available.
    }
}
