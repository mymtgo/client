<?php

use App\Models\LogEvent;
use App\Models\LogInstance;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

it('belongs to a log instance', function () {
    $instance = LogInstance::factory()->create();
    $event = LogEvent::factory()->create(['log_instance_id' => $instance->id]);

    expect($event->logInstance->is($instance))->toBeTrue();
});
