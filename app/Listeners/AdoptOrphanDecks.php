<?php

namespace App\Listeners;

use App\Events\AccountCreated;
use App\Models\Account;
use App\Models\Deck;

class AdoptOrphanDecks
{
    /**
     * File account-less decks under the account that has just been learned.
     *
     * Decks can exist before any account does: a restored device pulls its
     * whole deck history down by sync token, and the MTGO username is only
     * learned later, when a login line is parsed out of a log. Those decks
     * carry no account, and `Deck::forActiveAccount` filters on account_id
     * the moment an account row exists, so without this the entire restored
     * history disappears from the UI at the instant the user is identified.
     *
     * Only the device's first account adopts: with two MTGO accounts an
     * orphan could belong to either, and guessing files one player's decks
     * under the other.
     */
    public function handle(AccountCreated $event): void
    {
        if (Account::count() > 1) {
            return;
        }

        Deck::withTrashed()->whereNull('account_id')->update(['account_id' => $event->account->id]);
    }
}
