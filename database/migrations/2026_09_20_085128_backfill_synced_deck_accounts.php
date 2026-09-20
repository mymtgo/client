<?php

use App\Models\Account;
use App\Models\Deck;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * File decks that arrived by sync under the device's account.
     *
     * DeckBundleImporter never wrote account_id, so every deck restored from
     * the cloud landed unattached. `Deck::forActiveAccount` filters on that
     * column, so those decks were invisible everywhere in the app while the
     * rows sat in the database. The importer now fills it; this repairs the
     * decks imported before it did.
     *
     * Single-account devices only, matching AdoptOrphanDecks: with a second
     * MTGO account present an orphan could belong to either one.
     */
    public function up(): void
    {
        $accounts = Account::query()->get(['id']);

        if ($accounts->count() !== 1) {
            return;
        }

        Deck::withTrashed()->whereNull('account_id')->update(['account_id' => $accounts->first()->id]);
    }

    /**
     * Not reversible: the decks this attached are indistinguishable from the
     * ones that were correctly attached all along, so unsetting the column
     * would hide decks that were never broken.
     */
    public function down(): void {}
};
