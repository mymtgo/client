<?php

use App\Models\Card;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Same ceiling CreateMissingCards now enforces on new rows.
     */
    private const MAX_CATALOG_ID = 1_000_000;

    /**
     * Remove card stubs whose mtgo_id cannot be an MTGO catalog id.
     *
     * A bad decode put values like 89215000000 into this table. Nothing can
     * ever resolve them, but they are counted as missing card data, so the
     * card page reports a backlog that no amount of fetching will clear and
     * the Fetch button looks broken. CreateMissingCards now rejects them at
     * the door; these are the ones that got in first.
     *
     * Only unresolved rows go: a resolved row has a name and art that
     * something in the UI may be pointing at, whatever its id looks like.
     */
    public function up(): void
    {
        Card::query()
            ->whereNull('scryfall_id')
            ->whereRaw('cast(mtgo_id as integer) > ?', [self::MAX_CATALOG_ID])
            ->delete();
    }

    /**
     * Not reversible: the deleted rows carried nothing but an id that was
     * never a card.
     */
    public function down(): void {}
};
