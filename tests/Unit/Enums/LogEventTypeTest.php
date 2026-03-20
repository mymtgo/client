<?php

use App\Enums\LogEventType;

it('has all required new event types', function () {
    expect(LogEventType::GAME_RESULT->value)->toBe('game_result');
    expect(LogEventType::CARD_REVEALED->value)->toBe('card_revealed');
    expect(LogEventType::GAME_STARTED->value)->toBe('game_started');
    expect(LogEventType::MATCH_METADATA->value)->toBe('match_metadata');
    expect(LogEventType::USER_LOGGED_IN->value)->toBe('user_logged_in');
});
