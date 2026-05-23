<?php

use App\Actions\Logs\DetectLogRotation;
use App\Models\LogCursor;
use App\Models\LogInstance;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function makeInstanceWithCursor(array $instance = [], array $cursor = []): LogInstance
{
    $i = LogInstance::factory()->create(array_merge([
        'head_hash' => sha1('HEAD'),
        'file_ctime' => 1_000_000,
        'anchor_offset' => 128,
        'anchor_hash' => sha1('ANCHOR'),
    ], $instance));

    LogCursor::create(array_merge([
        'log_instance_id' => $i->id,
        'byte_offset' => 500,
        'last_observed_size' => 500,
        'verify_anchor_offset' => 200,
        'verify_anchor_hash' => sha1('VERIFY'),
    ], $cursor));

    return $i->fresh('cursor');
}

it('reports no rotation when nothing changed', function () {
    $instance = makeInstanceWithCursor();

    $result = DetectLogRotation::run($instance, [
        'size' => 600,
        'ctime' => 1_000_000,
        'head_hash' => sha1('HEAD'),
        'anchor_hash' => sha1('ANCHOR'),
    ]);

    expect($result->rotated)->toBeFalse()
        ->and($result->reason)->toBeNull();
});

it('detects truncation when current size is below last observed size', function () {
    $instance = makeInstanceWithCursor(cursor: ['last_observed_size' => 1000]);

    $result = DetectLogRotation::run($instance, [
        'size' => 50,
        'ctime' => 1_000_000,
        'head_hash' => sha1('HEAD'),
        'anchor_hash' => sha1('ANCHOR'),
    ]);

    expect($result->rotated)->toBeTrue()
        ->and($result->reason)->toBe('truncated');
});

it('detects ctime moving forward', function () {
    $instance = makeInstanceWithCursor();

    $result = DetectLogRotation::run($instance, [
        'size' => 600,
        'ctime' => 1_000_001,
        'head_hash' => sha1('HEAD'),
        'anchor_hash' => sha1('ANCHOR'),
    ]);

    expect($result->rotated)->toBeTrue()
        ->and($result->reason)->toBe('ctime_forward');
});

it('detects head hash change', function () {
    $instance = makeInstanceWithCursor();

    $result = DetectLogRotation::run($instance, [
        'size' => 600,
        'ctime' => 1_000_000,
        'head_hash' => sha1('NEW HEAD'),
        'anchor_hash' => sha1('ANCHOR'),
    ]);

    expect($result->rotated)->toBeTrue()
        ->and($result->reason)->toBe('head_changed');
});

it('detects anchor hash change inside ingested region', function () {
    $instance = makeInstanceWithCursor();

    $result = DetectLogRotation::run($instance, [
        'size' => 600,
        'ctime' => 1_000_000,
        'head_hash' => sha1('HEAD'),
        'anchor_hash' => sha1('DIFFERENT'),
    ]);

    expect($result->rotated)->toBeTrue()
        ->and($result->reason)->toBe('anchor_changed');
});

it('does not falsely rotate when anchor never recorded', function () {
    $instance = makeInstanceWithCursor(instance: ['anchor_hash' => null, 'anchor_offset' => null]);

    $result = DetectLogRotation::run($instance, [
        'size' => 600,
        'ctime' => 1_000_000,
        'head_hash' => sha1('HEAD'),
        'anchor_hash' => null,
    ]);

    expect($result->rotated)->toBeFalse();
});
