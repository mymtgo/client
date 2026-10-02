<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Sealed match codes (S6FRA) were read as constructed before sealed
     * support, so those runs were minted as constructed leagues. Re-mark
     * them; the limited views and sync key off the kind.
     */
    public function up(): void
    {
        DB::table('leagues')
            ->where('kind', 'constructed')
            ->whereRaw("format GLOB 'S[0-9]*'")
            ->update(['kind' => 'sealed', 'updated_at' => now()]);
    }

    public function down(): void
    {
        //
    }
};
