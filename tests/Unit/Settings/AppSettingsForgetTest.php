<?php

use App\Settings\AppSettings;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Storage::fake();
});

it('removes a key that exists', function () {
    $settings = new AppSettings;
    $settings->set('scratch', 'value');
    expect($settings->get('scratch'))->toBe('value');

    $settings->forget('scratch');

    expect($settings->get('scratch'))->toBeNull();
});

it('is a no-op when the key is absent', function () {
    $settings = new AppSettings;
    $settings->set('kept', 'yes');

    $settings->forget('missing');

    expect($settings->get('kept'))->toBe('yes');
});

it('preserves other keys when removing one', function () {
    $settings = new AppSettings;
    $settings->set('a', 1);
    $settings->set('b', 2);
    $settings->set('c', 3);

    $settings->forget('b');

    expect($settings->get('a'))->toBe(1);
    expect($settings->get('b'))->toBeNull();
    expect($settings->get('c'))->toBe(3);
});

it('is a no-op when settings.json does not exist', function () {
    $settings = new AppSettings;

    $settings->forget('anything');

    expect($settings->get('anything'))->toBeNull();
});
