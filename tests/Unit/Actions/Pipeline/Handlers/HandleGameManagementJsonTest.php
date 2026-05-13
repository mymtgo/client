<?php

use App\Actions\Pipeline\Handlers\HandleGameManagementJson;
use App\Actions\Pipeline\MetaMessage\SubHandler;
use App\Models\LogEvent;
use App\Support\PipelineContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    HandleGameManagementJson::$subHandlers = [];
});

afterEach(function () {
    HandleGameManagementJson::$subHandlers = [];
});

it('does nothing when raw_text contains no MetaMessage', function () {
    $event = LogEvent::factory()->create([
        'event_type' => 'game_management_json',
        'raw_text' => 'Message: {"FooMessage":{}}',
    ]);

    expect(fn () => (new HandleGameManagementJson)->handle($event, new PipelineContext))
        ->not->toThrow(Throwable::class);
});

it('dispatches to a registered sub-handler matching the parsed kind', function () {
    $spy = new class implements SubHandler
    {
        public bool $invoked = false;

        public array $parsed = [];

        public function apply(LogEvent $event, array $parsed, PipelineContext $context): void
        {
            $this->invoked = true;
            $this->parsed = $parsed;
        }
    };

    app()->instance(get_class($spy), $spy);
    HandleGameManagementJson::$subHandlers = ['chat' => get_class($spy)];

    // type=3 (chat) — bytes 0..3 length placeholder, byte 4 = type.
    // Strict extractText needs payloadStart >= 16 with declared length at payloadStart-4 matching trailing window.
    // Build: header (12 bytes 0) + length(4) = 5 + ascii payload "hi" (2)
    // Total = 12 + 4 + 2 = 18; payloadStart = 16; declared at offset 12 must equal 2.
    $bytes = array_fill(0, 12, 0);
    $bytes[4] = 3;
    $bytes = array_merge($bytes, [2, 0, 0, 0]); // declared length 2
    $bytes = array_merge($bytes, [ord('h'), ord('i')]);

    $raw = 'Message: {"GsMessageMessage":{"MetaMessage":['.implode(',', $bytes).']}}';

    $event = LogEvent::factory()->create([
        'event_type' => 'game_management_json',
        'raw_text' => $raw,
    ]);

    (new HandleGameManagementJson)->handle($event, new PipelineContext);

    expect($spy->invoked)->toBeTrue();
    expect($spy->parsed['kind'])->toBe('chat');
    expect($spy->parsed['type'])->toBe(3);
});

it('silently skips when no sub-handler is registered for the kind', function () {
    $bytes = array_fill(0, 12, 0);
    $bytes[4] = 3;
    $bytes = array_merge($bytes, [2, 0, 0, 0, ord('h'), ord('i')]);

    $raw = 'Message: {"GsMessageMessage":{"MetaMessage":['.implode(',', $bytes).']}}';

    $event = LogEvent::factory()->create([
        'event_type' => 'game_management_json',
        'raw_text' => $raw,
    ]);

    expect(fn () => (new HandleGameManagementJson)->handle($event, new PipelineContext))
        ->not->toThrow(Throwable::class);
});
