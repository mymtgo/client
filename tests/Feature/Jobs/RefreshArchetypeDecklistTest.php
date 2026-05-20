<?php

use App\Jobs\RefreshArchetypeDecklist;
use Illuminate\Support\Facades\Log;

it('logs and swallows when failed() runs', function () {
    Log::spy();

    $job = new RefreshArchetypeDecklist(42);
    $job->failed(new RuntimeException('boom'));

    Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context) {
        return str_contains($message, 'RefreshArchetypeDecklist')
            && $context['archetype_id'] === 42
            && $context['exception'] === 'boom';
    });
});
