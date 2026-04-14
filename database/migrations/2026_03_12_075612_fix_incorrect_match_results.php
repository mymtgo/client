<?php

use App\Actions\Matches\DetermineMatchResult;
use App\Actions\Matches\GetGameLog;
use App\Actions\Matches\SyncGameResults;
use App\Enums\LogEventType;
use App\Enums\MatchState;
use App\Facades\Mtgo;
use App\Models\LogCursor;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Re-evaluate match results for all completed matches using the
     * corrected game log parser (hyphen-safe username regex) and
     * format-agnostic concede detection (league + casual states).
     */
    public function up(): void
    {
        $username = LogCursor::first()?->local_username;

        if (! $username) {
            return;
        }

        Mtgo::setUsername($username);

        $matches = MtgoMatch::where('state', MatchState::Complete->value)
            ->whereNotNull('games_won')
            ->get();

        if ($matches->isEmpty()) {
            return;
        }

        $fixed = 0;

        foreach ($matches as $match) {
            try {
                $gameLog = GetGameLog::run($match->token);

                if (! $gameLog || empty($gameLog['results'])) {
                    continue;
                }

                $stateChanges = LogEvent::where('match_token', $match->token)
                    ->where('event_type', LogEventType::MATCH_STATE_CHANGED->value)
                    ->get();

                $result = DetermineMatchResult::run($gameLog['results'], $stateChanges);

                if ($result['wins'] !== $match->games_won || $result['losses'] !== $match->games_lost) {
                    Log::info("Fixing match {$match->id}: {$match->games_won}-{$match->games_lost} → {$result['wins']}-{$result['losses']}");

                    $match->update([
                        'games_won' => $result['wins'],
                        'games_lost' => $result['losses'],
                    ]);

                    SyncGameResults::run($match, $gameLog['results']);

                    $fixed++;
                }
            } catch (Throwable $e) {
                Log::warning("Skipping match {$match->id}: {$e->getMessage()}");
            }
        }

        if ($fixed > 0) {
            Log::info("Fixed {$fixed} match result(s)");
        }
    }

    /**
     * Not reversible — original values are not stored.
     */
    public function down(): void {}
};
