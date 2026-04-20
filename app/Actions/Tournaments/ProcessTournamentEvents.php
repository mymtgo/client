<?php

namespace App\Actions\Tournaments;

use App\Actions\Util\ExtractJson;
use App\Enums\TournamentState;
use App\Enums\TournamentStructure;
use App\Enums\TournamentTimelineEventType;
use App\Enums\TournamentType;
use App\Models\LogEvent;
use App\Models\Player;
use App\Models\Tournament;
use App\Models\TournamentStanding;
use App\Models\TournamentTimelineEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ProcessTournamentEvents
{
    public static function run(): void
    {
        $eventTypes = [
            'tournament_sync',
            'tournament_state_changed',
            'tournament_round_result',
            'tournament_player_eliminated',
            'tournament_ended',
            'tournament_match_state_changed',
        ];

        $events = LogEvent::whereIn('event_type', $eventTypes)
            ->whereNull('processed_at')
            ->orderBy('logged_at')
            ->get();

        foreach ($events as $event) {
            $processed = match ($event->event_type) {
                'tournament_sync' => self::processSync($event),
                'tournament_state_changed' => self::processStateChanged($event),
                'tournament_round_result' => self::processRoundResult($event),
                'tournament_player_eliminated' => self::processElimination($event),
                'tournament_ended' => self::processEnded($event),
                'tournament_match_state_changed' => self::processMatchStateChanged($event),
                default => true,
            };

            // Only mark as processed if the handler succeeded.
            // Events that couldn't find their tournament yet will be retried next cycle.
            if ($processed) {
                $event->update(['processed_at' => now()]);
            }
        }
    }

    /**
     * Limited events (Draft, Sealed, Cube, Queue) are handled by a separate domain.
     * Don't create Tournament records for them.
     */
    private static function isLimitedEvent(string $name): bool
    {
        $patterns = ['Draft', 'Sealed', 'Cube', 'Queue'];

        foreach ($patterns as $pattern) {
            if (str_contains($name, $pattern)) {
                return true;
            }
        }

        return str_starts_with($name, 'Limited ');
    }

    /**
     * Parse the tournament category from the MTGO event name.
     * e.g. "Modern Challenge 32" → "Challenge", "Standard Preliminary" → "Preliminary"
     */
    private static function parseCategoryFromName(string $name): ?string
    {
        // Strip leading format name and trailing player count
        $stripped = preg_replace('/^\S+\s+/', '', $name);
        $stripped = preg_replace('/\s+\d+$/', '', $stripped);

        // Handle "Duel Commander Trial 16" — two-word format prefix
        if (str_starts_with($name, 'Duel Commander ')) {
            $stripped = preg_replace('/^Duel Commander\s+/', '', $name);
            $stripped = preg_replace('/\s+\d+$/', '', $stripped);
        }

        return $stripped ?: null;
    }

    private static function processSync(LogEvent $event): bool
    {
        $json = ExtractJson::run($event->raw_text)->first();
        if (! is_array($json) || ! isset($json['EventToken'])) {
            return true;
        }

        $token = $json['EventToken'];
        $name = $json['Description'] ?? '';

        // Limited events are handled by a separate domain — don't create Tournament records.
        // Mark as processed so we don't retry.
        if (self::isLimitedEvent($name)) {
            return true;
        }

        // Tournament details are nested: PremiereEventSyncData.TournamentSyncData
        $tournamentData = $json['PremiereEventSyncData']['TournamentSyncData'] ?? [];

        // Format from GameStructureCd — strip the 'C' prefix (e.g. CMODERN → Modern)
        $format = null;
        if (isset($json['GameStructureCd'])) {
            $code = $json['GameStructureCd'];
            $format = str_starts_with($code, 'C')
                ? ucfirst(strtolower(substr($code, 1)))
                : $code;
        }

        // Category from the event name (e.g. "Modern Challenge 32" → "Challenge")
        $category = self::parseCategoryFromName($name);

        $existing = null;
        if (isset($json['EventID'])) {
            $existing = Tournament::where('event_id', $json['EventID'])->first();
        }
        if (! $existing) {
            $existing = Tournament::where('token', $token)->first();
        }

        $attributes = array_filter([
            'token' => $token,
            'event_id' => $json['EventID'] ?? null,
            'type' => TournamentType::fromPlayFormatCd($json['PlayFormatCd'] ?? null)?->value,
            'name' => $json['Description'] ?? null,
            'category' => $category,
            'format' => $format,
            'description' => isset($json['FormatDescription']) ? StripBbCode::run($json['FormatDescription']) : null,
            'tournament_structure' => isset($tournamentData['TournamentStructureCd'])
                ? TournamentStructure::fromMtgoCode($tournamentData['TournamentStructureCd'])
                : null,
            'max_rounds' => ($tournamentData['NumberOfRounds'] ?? 0) ?: null,
            'min_players' => ($tournamentData['MinPlayers'] ?? 0) ?: null,
            'max_players' => ($tournamentData['MaxPlayers'] ?? 0) ?: null,
            'player_count' => count($json['Players'] ?? []),
            'scheduled_at' => isset($json['StartDate'])
                ? Carbon::parse($json['StartDate'])->utc()
                : null,
        ], fn ($v) => $v !== null);

        if ($existing) {
            $existing->update($attributes);
            $tournament = $existing;
        } else {
            $tournament = Tournament::create($attributes);
        }

        foreach ($json['Players'] ?? [] as $player) {
            if (isset($player['LoginID'], $player['PlayerName'])) {
                Player::updateOrCreate(
                    ['login_id' => $player['LoginID']],
                    ['username' => $player['PlayerName']],
                );
            }
        }

        Log::channel('pipeline')->info("ProcessTournamentEvents: synced tournament #{$tournament->id}", [
            'token' => $token,
            'name' => $tournament->name,
            'players' => count($json['Players'] ?? []),
        ]);

        return true;
    }

    private static function processStateChanged(LogEvent $event): bool
    {
        $token = $event->match_token;
        $text = $event->raw_text;

        // Only update existing tournaments — processSync is responsible for creation.
        // If no tournament exists yet, leave unprocessed for retry next cycle.
        $tournament = Tournament::where('token', $token)->first();
        if (! $tournament) {
            return false;
        }

        $toState = null;
        if (preg_match('/to (\S+)\)/', $text, $m)) {
            $toState = TournamentState::fromMtgoState($m[1]);
        }

        if (! $toState) {
            return true;
        }

        $updates = ['state' => $toState];

        if ($toState === TournamentState::RoundInProgress) {
            $updates['current_round'] = ($tournament->current_round ?? 0) + 1;
        }

        if ($toState === TournamentState::Completed) {
            $updates['ended_at'] = $event->logged_at;
        }

        if ($toState !== TournamentState::AwaitingPlayers && ! $tournament->started_at) {
            $updates['started_at'] = $event->logged_at;
        }

        $tournament->update($updates);

        TournamentTimelineEvent::create([
            'tournament_id' => $tournament->id,
            'event_type' => TournamentTimelineEventType::StateChanged,
            'payload' => ['to_state' => $toState->value],
            'occurred_at' => $event->logged_at,
        ]);

        return true;
    }

    private static function processRoundResult(LogEvent $event): bool
    {
        $json = ExtractJson::run($event->raw_text)->first();
        if (! is_array($json) || ! isset($json['Token'], $json['Round'], $json['Results'])) {
            return true;
        }

        $tournament = Tournament::where('token', $json['Token'])->first();
        if (! $tournament) {
            return false;
        }

        $round = (int) $json['Round'];
        $tournament->update([
            'current_round' => $round,
            'player_count' => count($json['Results']),
        ]);

        $loginIds = collect($json['Results'])->pluck('LoginID')->all();
        $usernameMap = Player::whereIn('login_id', $loginIds)
            ->pluck('username', 'login_id')
            ->all();

        foreach ($json['Results'] as $result) {
            $loginId = (int) $result['LoginID'];

            $opponents = collect($result['OpponentResults'] ?? []);
            $wins = $opponents->filter(fn ($r) => $r['Win'] > $r['Loss'])->count();
            $losses = $opponents->filter(fn ($r) => $r['Loss'] > $r['Win'])->count();
            $draws = $opponents->filter(fn ($r) => $r['Win'] === $r['Loss'])->count();

            TournamentStanding::updateOrCreate(
                [
                    'tournament_id' => $tournament->id,
                    'round' => $round,
                    'login_id' => $loginId,
                ],
                [
                    'username' => $usernameMap[$loginId] ?? null,
                    'rank' => (int) $result['Rank'],
                    'points' => (int) $result['Points'],
                    'wins' => $wins,
                    'losses' => $losses,
                    'draws' => $draws,
                    'opponent_match_win_pct' => isset($result['OpponentMatchWinPercentage'])
                        ? (float) $result['OpponentMatchWinPercentage']
                        : null,
                    'game_win_pct' => isset($result['GameWinPercentage'])
                        ? (float) $result['GameWinPercentage']
                        : null,
                ],
            );
        }

        TournamentTimelineEvent::create([
            'tournament_id' => $tournament->id,
            'round' => $round,
            'event_type' => TournamentTimelineEventType::RoundResult,
            'payload' => ['player_count' => count($json['Results'])],
            'occurred_at' => $event->logged_at,
        ]);

        Log::channel('pipeline')->info("ProcessTournamentEvents: round {$round} results for tournament #{$tournament->id}", [
            'players' => count($json['Results']),
        ]);

        return true;
    }

    private static function processElimination(LogEvent $event): bool
    {
        $json = ExtractJson::run($event->raw_text)->first();
        if (! is_array($json) || ! isset($json['Token'], $json['LoginID'])) {
            return true;
        }

        $tournament = Tournament::where('token', $json['Token'])->first();
        if (! $tournament) {
            return false;
        }

        $loginId = (int) $json['LoginID'];
        $username = Player::where('login_id', $loginId)->value('username');

        TournamentTimelineEvent::create([
            'tournament_id' => $tournament->id,
            'round' => $tournament->current_round,
            'event_type' => TournamentTimelineEventType::PlayerEliminated,
            'login_id' => $loginId,
            'username' => $username,
            'payload' => ['reason' => $json['Reason'] ?? null],
            'occurred_at' => $event->logged_at,
        ]);

        return true;
    }

    private static function processEnded(LogEvent $event): bool
    {
        $json = ExtractJson::run($event->raw_text)->first();
        if (! is_array($json) || ! isset($json['Token'])) {
            return true;
        }

        $tournament = Tournament::where('token', $json['Token'])->first();
        if (! $tournament) {
            return false;
        }

        $tournament->update([
            'state' => TournamentState::Completed,
            'ended_at' => isset($json['EndDate']) ? $json['EndDate'] : $event->logged_at,
        ]);

        TournamentTimelineEvent::create([
            'tournament_id' => $tournament->id,
            'event_type' => TournamentTimelineEventType::StateChanged,
            'payload' => ['to_state' => TournamentState::Completed->value],
            'occurred_at' => $event->logged_at,
        ]);

        return true;
    }

    private static function processMatchStateChanged(LogEvent $event): bool
    {
        // Tournament match events reference match tokens, not tournament tokens.
        // Low-priority feed events — mark as processed.
        return true;
    }
}
