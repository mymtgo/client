<?php

declare(strict_types=1);

namespace App\Actions\Sync;

use App\Exceptions\Sync\LimitedRequiresSupporterException;
use App\Exceptions\Sync\NotLinkedException;
use App\Exceptions\Sync\SlotLimitException;
use App\Jobs\RunSyncJob;
use App\Models\Deck;
use App\Services\Sync\DeckClientId;
use App\Services\Sync\SyncApi;
use App\Services\Sync\SyncTokens;

/**
 * Turns cloud sync on or off for one deck through the API, then mirrors
 * the server's answer locally (ApplyDeckSyncSlots). Enabling queues a run
 * so the deck's history starts uploading straight away.
 *
 * @throws NotLinkedException when this device has no account
 * @throws SlotLimitException when the server has no free slot
 * @throws LimitedRequiresSupporterException when the deck is limited and the account is on the free tier
 */
class SetDeckCloudSync
{
    /**
     * @return array{limit: int|null, used: int, decks: list<array{client_id: string, enabled_at: string, disabled_at: string|null, frees_at: string|null}>}
     */
    public static function run(Deck $deck, bool $enabled): array
    {
        if (! app(SyncTokens::class)->linked()) {
            throw new NotLinkedException;
        }

        $slots = app(SyncApi::class)->setDeckSync(
            DeckClientId::for((string) $deck->mtgo_id),
            $enabled,
        );

        ApplyDeckSyncSlots::run($slots);

        if ($enabled) {
            RunSyncJob::dispatch();
        }

        return $slots;
    }
}
