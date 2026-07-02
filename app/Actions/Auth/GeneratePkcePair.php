<?php

namespace App\Actions\Auth;

use Illuminate\Support\Str;

/**
 * RFC 7636 PKCE pair (S256) + an OAuth `state` CSRF token. Pure — the
 * caller stashes what it needs.
 */
final class GeneratePkcePair
{
    /**
     * @return array{verifier: string, challenge: string, state: string}
     */
    public function run(): array
    {
        // 64 random bytes → 86 base64url chars (within the 43–128 range).
        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return [
            'verifier' => $verifier,
            'challenge' => $challenge,
            'state' => Str::random(40),
        ];
    }
}
