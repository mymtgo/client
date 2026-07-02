<?php

use App\Actions\Auth\GeneratePkcePair;
use Tests\TestCase;

uses(TestCase::class);

it('produces an RFC 7636 S256 verifier/challenge and a state token', function () {
    $pair = app(GeneratePkcePair::class)->run();

    // verifier: 43–128 unreserved base64url chars
    expect(strlen($pair['verifier']))->toBeGreaterThanOrEqual(43)->toBeLessThanOrEqual(128);
    expect($pair['verifier'])->toMatch('/^[A-Za-z0-9\-._~]+$/');

    // challenge = base64url(sha256(verifier)), no padding
    $expected = rtrim(strtr(base64_encode(hash('sha256', $pair['verifier'], true)), '+/', '-_'), '=');
    expect($pair['challenge'])->toBe($expected);
    expect($pair['challenge'])->not->toContain('=');

    // state: non-empty, unpredictable
    expect($pair['state'])->not->toBeEmpty();
    expect(app(GeneratePkcePair::class)->run()['verifier'])->not->toBe($pair['verifier']);
});
