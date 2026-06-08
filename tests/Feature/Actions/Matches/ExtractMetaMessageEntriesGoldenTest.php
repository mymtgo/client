<?php

use App\Actions\Matches\ExtractGameResults;
use App\Actions\Matches\ExtractMetaMessageEntries;
use App\Actions\Matches\ParseGameLogBinary;
use App\Models\LogEvent;
use App\Models\LogInstance;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/**
 * Seed log_events for a match by scanning the real mtgo.log fixture for all
 * MetaMessage lines belonging to $token.
 */
function seedLogEventsForMatch(string $matchLogPath, string $token): void
{
    $instance = LogInstance::factory()->create();
    $fh = fopen($matchLogPath, 'rb');
    $offset = 0;

    while (($line = fgets($fh)) !== false) {
        if (str_contains($line, $token) && str_contains($line, 'MetaMessage')) {
            $jsonStart = strpos($line, '{');
            $jsonEnd = strrpos($line, '}');
            if ($jsonStart === false || $jsonEnd === false) {
                $offset += strlen($line);

                continue;
            }

            preg_match('/^(\d{2}):(\d{2}):(\d{2})/', $line, $tm);
            $ts = $tm[1].':'.$tm[2].':'.$tm[3];

            LogEvent::factory()->create([
                'log_instance_id' => $instance->id,
                'match_token' => $token,
                'event_type' => 'game_management_json',
                'timestamp' => $ts,
                'logged_at' => Carbon::parse('2026-03-18 00:00:00', 'UTC'),
                'byte_offset_start' => $offset,
                'byte_offset_end' => $offset + strlen($line),
                'raw_text' => trim($line),
            ]);
        }
        $offset += strlen($line);
    }

    fclose($fh);
}

dataset('realCapturedMatches', [
    'match 0fb9b76c (2 games, anticloser vs EridanAmpora)' => [
        '0fb9b76c-0fe9-447d-8df3-3b8db582469e',
        'anticloser',
    ],
]);

it('MetaMessage entries produce per-game-equivalent ExtractGameResults output to .dat parse', function (string $token, string $localPlayer) {
    $logPath = base_path('storage/app/mtgo.log');
    $datPath = base_path("storage/app/91F5DC46A0AFBF283E8FD4E9E184F175/Match_GameLog_{$token}.dat");

    if (! is_file($logPath) || ! is_file($datPath)) {
        $this->markTestSkipped('captured-match fixture not present');
    }

    seedLogEventsForMatch($logPath, $token);

    $metaEntries = ExtractMetaMessageEntries::run($token);

    expect($metaEntries)->not->toBeEmpty('log_events seeding produced no entries — fixture path likely wrong');

    $datRaw = file_get_contents($datPath);
    $datDecoded = ParseGameLogBinary::run($datRaw);
    expect($datDecoded)->not->toBeNull();
    $datEntries = $datDecoded['entries'];

    $metaResult = ExtractGameResults::run($metaEntries, $localPlayer);
    $datResult = ExtractGameResults::run($datEntries, $localPlayer);

    // Players + per-game outcomes must match exactly.
    expect($metaResult['players'])->toEqualCanonicalizing($datResult['players']);
    expect(count($metaResult['games']))->toBe(count($datResult['games']));

    foreach ($metaResult['games'] as $i => $metaGame) {
        $datGame = $datResult['games'][$i];
        expect($metaGame['winner'])->toBe($datGame['winner'], "Game {$i} winner mismatch");
        expect($metaGame['loser'])->toBe($datGame['loser'], "Game {$i} loser mismatch");
        expect($metaGame['end_reason'])->toBe($datGame['end_reason'], "Game {$i} end_reason mismatch");
        expect($metaGame['on_play'])->toBe($datGame['on_play'], "Game {$i} on_play mismatch");
    }

    // NOTE: $metaResult['match_decided'] vs $datResult['match_decided'] is
    // EXPECTED to diverge. MTGO writes "@PX wins the match N-N" only to the
    // post-match .dat game log file, never to the live mtgo.log MetaMessage
    // stream. The final match decision in production flows through
    // DetermineMatchResult::run() which derives decided=true via $thresholdMet
    // (wins>=2 in BO3) whenever per-game winners are known. Not asserting
    // match_decided here is intentional and documented in the v1 spec.
})->with('realCapturedMatches');
