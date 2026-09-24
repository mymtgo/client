<?php

namespace App\Actions\Replays;

use App\Models\MtgoMatch;
use App\Services\Sync\SyncApi;

class RevokeReplayShare
{
    /** Switches a match's shared link off on the server, then forgets it here. */
    public static function run(MtgoMatch $match): void
    {
        if ($match->replay_share_uuid !== null) {
            app(SyncApi::class)->revokeReplay($match->replay_share_uuid);
        }

        // Without timestamps, for the same reason as ShareReplay.
        MtgoMatch::withoutTimestamps(fn () => $match->update([
            'replay_share_uuid' => null,
            'replay_share_url' => null,
            'replay_shared_at' => null,
        ]));
    }
}
