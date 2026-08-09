<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Watermark for the deck XML file's own Timestamp attribute.
     *
     * SyncDecks previously gated on the newest DeckVersion's modified_at, which
     * never advances when the file reverts to a list we already hold — the
     * version is reused, not recreated. Null means "never synced", so existing
     * decks process once and settle.
     */
    public function up(): void
    {
        if (Schema::hasColumn('decks', 'deck_file_synced_at')) {
            return;
        }

        Schema::table('decks', function (Blueprint $table) {
            $table->timestamp('deck_file_synced_at')->nullable()->after('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->dropColumn('deck_file_synced_at');
        });
    }
};
