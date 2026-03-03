<?php

use App\Facades\Mtgo;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the application returns a successful response', function () {
    Mtgo::shouldReceive('canRun')
        ->andReturn(false);

    $this->withoutVite();

    $response = $this->get('/');

    $response->assertStatus(200);
});
