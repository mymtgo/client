<?php

use App\Actions\Util\RepairJson;
use Tests\TestCase;

uses(TestCase::class);

it('decodes well formed json unchanged', function () {
    expect(RepairJson::firstObject('prefix Message: {"a":1,"b":{"c":2}} Receiver: X'))
        ->toBe(['a' => 1, 'b' => ['c' => 2]]);
});

it('repairs a payload missing its outer closing brace', function () {
    $line = file_get_contents(base_path('tests/Fixtures/log_samples/draft_pending_pick.txt'));

    $json = RepairJson::firstObject($line);

    expect($json)->not->toBeNull()
        ->and($json['DraftToken'])->toBe('791bacca-caea-4d88-b6c7-3bc067d412c2')
        ->and($json['PendingPick']['CardsAvailable'])->toHaveCount(14)
        ->and($json['PendingPick']['PackID'])->toBe(143682097)
        ->and($json['PendingPick']['SelectionsPerPick'])->toBe(1);
});

it('returns null when there is no object', function () {
    expect(RepairJson::firstObject('no braces here'))->toBeNull();
});

it('returns null when more than three braces are missing', function () {
    expect(RepairJson::firstObject('{"a":{"b":{"c":{"d":{"e":1'))->toBeNull();
});
