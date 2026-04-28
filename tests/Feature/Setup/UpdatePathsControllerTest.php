<?php

use App\Facades\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('updates log path', function () {
    $dir = sys_get_temp_dir().'/setup-paths-'.uniqid();
    mkdir($dir);

    $this->patch('/setup/log-path', ['path' => $dir])
        ->assertRedirect('/setup');

    expect(AppSettings::logPath())->toBe($dir);
});

it('updates data path', function () {
    $dir = sys_get_temp_dir().'/setup-paths-'.uniqid();
    mkdir($dir);

    $this->patch('/setup/data-path', ['path' => $dir])
        ->assertRedirect('/setup');

    expect(AppSettings::logDataPath())->toBe($dir);
});

it('requires path', function () {
    $this->patch('/setup/log-path', [])
        ->assertSessionHasErrors('path');
});
