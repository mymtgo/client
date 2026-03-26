<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Split phantom leagues that exceed 5 matches after consolidation.
     *
     * Takes the first 5 matches (by started_at) as one complete league,
     * then groups remaining matches into new leagues of up to 5.
     */
    public function up(): void
    {
        $oversized = DB::table('leagues as l')
            ->where('l.phantom', true)
            ->whereNull('l.deleted_at')
            ->whereRaw('(SELECT COUNT(*) FROM matches WHERE league_id = l.id) > 5')
            ->get();

        foreach ($oversized as $league) {
            $matches = DB::table('matches')
                ->where('league_id', $league->id)
                ->orderBy('started_at')
                ->pluck('id')
                ->values();

            // First 5 stay on the original league — mark it complete
            $overflow = $matches->slice(5)->values();

            DB::table('leagues')
                ->where('id', $league->id)
                ->update(['state' => 'complete']);

            // Create new leagues for overflow in chunks of 5
            foreach ($overflow->chunk(5) as $chunk) {
                $firstMatch = DB::table('matches')->where('id', $chunk->first())->first();

                $newLeague = [
                    'token' => Str::random(),
                    'format' => $league->format,
                    'phantom' => true,
                    'deck_version_id' => $league->deck_version_id,
                    'state' => $chunk->count() >= 5 ? 'complete' : 'active',
                    'started_at' => $firstMatch->started_at,
                    'name' => 'Phantom League '.date('d-m-Y h:ia', strtotime($firstMatch->started_at)),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $newId = DB::table('leagues')->insertGetId($newLeague);

                DB::table('matches')
                    ->whereIn('id', $chunk)
                    ->update(['league_id' => $newId]);
            }
        }
    }

    public function down(): void
    {
        // Data migration — not reversible
    }
};
