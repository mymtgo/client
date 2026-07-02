<?php

namespace App\Actions\Auth;

use App\Data\LocalIdentity;
use App\Facades\Mtgo;
use App\Models\AppAccount;

/**
 * The username-mismatch guard: identity resolves only when the MTGO
 * username observed in the logs matches the bound cloud account. Unresolved
 * or mismatched → null, and callers hold the push (log nothing upstream) —
 * someone else's session must never be recorded against this account.
 */
final class ResolveLocalIdentity
{
    public function run(): ?LocalIdentity
    {
        $account = AppAccount::query()->active()->first();

        if ($account === null) {
            return null;
        }

        $username = Mtgo::getUsername();

        if (blank($username) || $username !== $account->mtgo_username) {
            return null;
        }

        return new LocalIdentity(
            mtgoPlayerId: $account->mtgo_player_id,
            mtgoUsername: $account->mtgo_username,
        );
    }
}
