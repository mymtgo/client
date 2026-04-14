<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Consolidate duplicate phantom leagues for the same deck+format and clean up orphans.
     *
     * 1. Delete phantom leagues with zero matches (orphans from null-deck creation).
     * 2. For each deck+format combo with multiple active phantom leagues, keep the
     *    oldest and move all matches into it, then delete the extras.
     * 3. Mark any phantom league with 5+ complete matches as complete.
     */
    public function up(): void
    {
        // 1. Delete orphan phantom leagues (no matches at all)
        $orphanIds = DB::table('leagues')
            ->where('phantom', true)
            ->whereNull('deleted_at')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('matches')
                    ->whereRaw('matches.league_id = leagues.id');
            })
            ->pluck('id');

        if ($orphanIds->isNotEmpty()) {
            DB::table('leagues')->whereIn('id', $orphanIds)->delete();
        }

        // 2. Consolidate duplicates: group active phantom leagues by deck_id + format
        $duplicateGroups = DB::table('leagues as l')
            ->join('deck_versions as dv', 'dv.id', '=', 'l.deck_version_id')
            ->where('l.phantom', true)
            ->where('l.state', 'active')
            ->whereNull('l.deleted_at')
            ->select('dv.deck_id', 'l.format', DB::raw('COUNT(*) as cnt'))
            ->groupBy('dv.deck_id', 'l.format')
            ->having('cnt', '>', 1)
            ->get();

        foreach ($duplicateGroups as $group) {
            $leagueIds = DB::table('leagues as l')
                ->join('deck_versions as dv', 'dv.id', '=', 'l.deck_version_id')
                ->where('l.phantom', true)
                ->where('l.state', 'active')
                ->whereNull('l.deleted_at')
                ->where('dv.deck_id', $group->deck_id)
                ->where('l.format', $group->format)
                ->orderBy('l.started_at')
                ->select('l.id')
                ->get()
                ->pluck('id');

            $keepId = $leagueIds->first();
            $mergeIds = $leagueIds->slice(1)->values();

            // Move all matches from duplicate leagues into the keeper
            DB::table('matches')
                ->whereIn('league_id', $mergeIds)
                ->update(['league_id' => $keepId]);

            // Delete the now-empty duplicate leagues
            DB::table('leagues')->whereIn('id', $mergeIds)->delete();

            // Split if > 5 matches: keep first 5, overflow into new leagues
            $allMatches = DB::table('matches')
                ->where('league_id', $keepId)
                ->orderBy('started_at')
                ->pluck('id')
                ->values();

            if ($allMatches->count() > 5) {
                $keeper = DB::table('leagues')->where('id', $keepId)->first();

                foreach ($allMatches->slice(5)->chunk(5) as $chunk) {
                    $firstMatch = DB::table('matches')->where('id', $chunk->first())->first();

                    $newId = DB::table('leagues')->insertGetId([
                        'token' => Str::random(),
                        'format' => $keeper->format,
                        'phantom' => true,
                        'deck_version_id' => $keeper->deck_version_id,
                        'state' => $chunk->count() >= 5 ? 'complete' : 'active',
                        'started_at' => $firstMatch->started_at,
                        'name' => 'Phantom League '.date('d-m-Y h:ia', strtotime($firstMatch->started_at)),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('matches')
                        ->whereIn('id', $chunk)
                        ->update(['league_id' => $newId]);
                }
            }
        }

        // 3. Mark phantom leagues with 5+ complete matches as complete
        $fullLeagues = DB::table('leagues as l')
            ->where('l.phantom', true)
            ->where('l.state', 'active')
            ->whereNull('l.deleted_at')
            ->whereRaw('(SELECT COUNT(*) FROM matches WHERE league_id = l.id AND state = ?) >= 5', ['complete'])
            ->pluck('l.id');

        if ($fullLeagues->isNotEmpty()) {
            DB::table('leagues')
                ->whereIn('id', $fullLeagues)
                ->update(['state' => 'complete']);
        }
    }

    public function down(): void
    {
        // Data migration — not reversible
    }
};
