<?php

use App\Actions\Logs\IngestLogInstance;
use App\Models\Account;
use App\Models\LogCursor;
use App\Models\LogEvent;
use App\Models\LogInstance;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function writeLog(string $path, string $contents, ?int $ctime = null): void
{
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $contents);
    if ($ctime !== null) {
        touch($path, $ctime, $ctime);
    }
}

function loginLine(): string
{
    // Matches IngestLog::isNewEventLine + buildEventRow regex + detectLogin pattern.
    return "12:00:00 [INF] (Login|MtGO Login Success) Username: SomeUser\n";
}

function classifyableLine(): string
{
    // Real Match State Changed line shape — ClassifyLogEvent maps this to
    // event_type='match_state_changed' and carries a match_token. The header
    // parser splits category/context at the first '|', and the classifier
    // regex `/Match State Changed for (?<token>[a-f0-9\-]+)/i` matches the
    // hex-dash token in the context.
    return "12:00:01 [INF] (Game Management|Match State Changed for aaaa-1111 from X to Y) Some payload.\n";
}

beforeEach(function () {
    $this->logPath = sys_get_temp_dir().'/mtgo_test_'.bin2hex(random_bytes(4)).'.log';
});

afterEach(function () {
    @unlink($this->logPath);
});

it('creates a fresh LogInstance + LogCursor on first ingest of a new file', function () {
    writeLog($this->logPath, loginLine());

    IngestLogInstance::run($this->logPath);

    expect(LogInstance::count())->toBe(1)
        ->and(LogCursor::count())->toBe(1);

    $cursor = LogCursor::first();
    expect($cursor->byte_offset)->toBeGreaterThan(0)
        ->and($cursor->logInstance->file_path)->toBe($this->logPath);
});

it('ingests only new bytes on second invocation', function () {
    writeLog($this->logPath, loginLine());
    IngestLogInstance::run($this->logPath);

    $eventsAfterFirst = LogEvent::count();

    file_put_contents($this->logPath, classifyableLine(), FILE_APPEND);
    IngestLogInstance::run($this->logPath);

    expect(LogEvent::count())->toBeGreaterThan($eventsAfterFirst);
});

it('seals the active instance and creates a new one when ctime moves forward', function () {
    writeLog($this->logPath, loginLine(), ctime: 1_000_000);
    IngestLogInstance::run($this->logPath);

    $firstInstanceId = LogInstance::query()->whereNull('sealed_at')->value('id');

    // On Linux/macOS, touch() with the third arg sets atime, not ctime.
    // ctime is controlled by the kernel and is bumped by metadata changes.
    // chmod() bumps ctime; usleep ensures the second-resolution ctime advances.
    usleep(1_100_000);
    writeLog($this->logPath, loginLine());
    chmod($this->logPath, 0644);

    IngestLogInstance::run($this->logPath);

    expect(LogInstance::find($firstInstanceId)->isSealed())->toBeTrue()
        ->and(LogInstance::query()->whereNull('sealed_at')->count())->toBe(1)
        ->and(LogInstance::query()->whereNull('sealed_at')->value('id'))->not->toBe($firstInstanceId);
});

it('seals on truncation (file shrinks below last observed size)', function () {
    writeLog($this->logPath, loginLine().classifyableLine());
    IngestLogInstance::run($this->logPath);

    writeLog($this->logPath, ''); // truncate
    IngestLogInstance::run($this->logPath);

    expect(LogInstance::query()->whereNotNull('sealed_at')->where('seal_reason', 'truncated')->count())->toBe(1);
});

it('increments stuck_ticks when file grows but classifier emits no advance', function () {
    // Write only garbage that won't match isNewEventLine.
    writeLog($this->logPath, "GARBAGE\nGARBAGE\n");
    IngestLogInstance::run($this->logPath);

    file_put_contents($this->logPath, "MORE GARBAGE\n", FILE_APPEND);
    IngestLogInstance::run($this->logPath);

    $cursor = LogCursor::first();
    expect($cursor->stuck_ticks)->toBeGreaterThan(0);
});

it('resets stuck_ticks to zero after cursor advances', function () {
    writeLog($this->logPath, "GARBAGE\n");
    IngestLogInstance::run($this->logPath);
    file_put_contents($this->logPath, "GARBAGE 2\n", FILE_APPEND);
    IngestLogInstance::run($this->logPath);

    expect(LogCursor::first()->stuck_ticks)->toBeGreaterThan(0);

    file_put_contents($this->logPath, loginLine(), FILE_APPEND);
    IngestLogInstance::run($this->logPath);

    expect(LogCursor::first()->stuck_ticks)->toBe(0);
});

it('force-seals the instance after stuck_ticks exceeds threshold', function () {
    writeLog($this->logPath, "GARBAGE\n");
    IngestLogInstance::run($this->logPath);

    // Drive stuck_ticks past threshold (60). Do this by manually incrementing
    // to avoid 60 file-grow cycles in the test.
    LogCursor::first()->update(['stuck_ticks' => 60]);

    file_put_contents($this->logPath, "MORE GARBAGE\n", FILE_APPEND);
    IngestLogInstance::run($this->logPath);

    expect(LogInstance::query()->whereNotNull('sealed_at')->where('seal_reason', 'stuck_force_reset')->count())->toBe(1);
});

it('does nothing for a non-existent path', function () {
    IngestLogInstance::run('/nonexistent/path/mtgo.log');

    expect(LogInstance::count())->toBe(0)
        ->and(LogCursor::count())->toBe(0);
});

it('does nothing for a null path', function () {
    IngestLogInstance::run(null);

    expect(LogEvent::count())->toBe(0)
        ->and(LogInstance::count())->toBe(0)
        ->and(LogCursor::count())->toBe(0);
});

it('captures local_username on the active LogInstance when a Login event is parsed', function () {
    writeLog($this->logPath, "15:52:41 [INF] (Login|MtGO Login Success) Username: testplayer (3022021)\n");

    IngestLogInstance::run($this->logPath);

    $instance = LogInstance::query()->whereNull('sealed_at')->first();
    expect($instance->local_username)->toBe('testplayer');
});

it('updates local_username when the account switches mid-session', function () {
    writeLog($this->logPath, "15:52:41 [INF] (Login|MtGO Login Success) Username: player_one (3022021)\n");
    IngestLogInstance::run($this->logPath);

    expect(LogInstance::query()->whereNull('sealed_at')->value('local_username'))->toBe('player_one');

    file_put_contents(
        $this->logPath,
        "16:10:00 [INF] (Login|MtGO Login Success) Username: player_two (4055032)\n",
        FILE_APPEND
    );
    IngestLogInstance::run($this->logPath);

    expect(LogInstance::query()->whereNull('sealed_at')->value('local_username'))->toBe('player_two');
});

it('registers and activates new Accounts on login detection', function () {
    writeLog($this->logPath, "15:52:41 [INF] (Login|MtGO Login Success) Username: newplayer (3022021)\n");

    IngestLogInstance::run($this->logPath);

    $account = Account::where('username', 'newplayer')->first();
    expect($account)->not->toBeNull()
        ->and($account->tracked)->toBeTrue()
        ->and($account->active)->toBeTrue();
});

it('is idempotent — running twice on the same file does not duplicate events', function () {
    writeLog($this->logPath, loginLine().classifyableLine());

    IngestLogInstance::run($this->logPath);
    $countAfterFirst = LogEvent::count();

    IngestLogInstance::run($this->logPath);

    expect(LogEvent::count())->toBe($countAfterFirst);
});

it('classifies match_state_changed events and preserves the match_token', function () {
    writeLog($this->logPath, classifyableLine());

    IngestLogInstance::run($this->logPath);

    $event = LogEvent::where('event_type', 'match_state_changed')->first();
    expect($event)->not->toBeNull()
        ->and($event->match_token)->toBe('aaaa-1111');
});

it('preserves tournament_token when ingesting tournament_state_changed events', function () {
    $content = file_get_contents(base_path('tests/Fixtures/log_samples/tournament_state_changed.txt'));
    writeLog($this->logPath, rtrim($content)."\n");

    IngestLogInstance::run($this->logPath);

    $event = LogEvent::where('event_type', 'tournament_state_changed')->first();
    expect($event)->not->toBeNull()
        ->and($event->tournament_token)->toBe('b197b9e8-0d08-4227-aa17-ba38cb4c1731');
});

it('does not commit incomplete events at end of file (no trailing newline)', function () {
    writeLog(
        $this->logPath,
        "15:04:11 [INF] (Game Management|Match State Changed for cccc-3333 from X to Y) Complete event.\n".
        '15:04:12 [INF] (Game Management|Match State Changed for dddd-4444 from X to Y) This has no newline'
    );

    IngestLogInstance::run($this->logPath);

    $events = LogEvent::all();
    expect($events)->toHaveCount(1)
        ->and($events->first()->match_token)->toBe('cccc-3333');
});

it('persists events across multiple 500-row insert chunks', function () {
    $lines = [];
    for ($i = 0; $i < 650; $i++) {
        $time = sprintf('15:%02d:%02d', intdiv($i, 60) % 60, $i % 60);
        $token = sprintf('%04d-0000-0000-0000-000000000000', $i);
        $lines[] = "{$time} [INF] (Game Management|Match State Changed for {$token} from X to Y) Log line number {$i}.";
    }
    writeLog($this->logPath, implode("\n", $lines)."\n");

    IngestLogInstance::run($this->logPath);

    expect(LogEvent::count())->toBe(650)
        ->and(LogCursor::first()->byte_offset)->toBe(filesize($this->logPath));
});
