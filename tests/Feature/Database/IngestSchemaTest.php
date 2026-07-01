<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('migrates the ported ingest tables', function () {
    expect(Schema::hasTable('log_instances'))->toBeTrue();
    expect(Schema::hasTable('log_cursors'))->toBeTrue();
    expect(Schema::hasTable('log_events'))->toBeTrue();
});

it('carries the final log_events columns', function () {
    $columns = Schema::getColumnListing('log_events');

    expect($columns)->toContain('log_instance_id', 'match_token', 'match_id', 'tournament_token', 'event_type', 'username', 'processed_at');
});
