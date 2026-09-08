<?php

use App\Actions\Util\Winrate;

it('divides wins by every match played, draws included', function () {
    // 83W 98L 6D shows as 44%, matching the record the player sees.
    expect(Winrate::percentage(83, 98, 6))->toBe(44);
});

it('treats a missing draws argument as zero', function () {
    expect(Winrate::percentage(1, 1))->toBe(50);
});

it('returns zero when only draws were played', function () {
    expect(Winrate::percentage(0, 0, 3))->toBe(0);
});
