<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function getIndexNames(string $table): array
{
    return collect(DB::select("PRAGMA index_list({$table})"))
        ->pluck('name')
        ->all();
}

it('adds missing FK indexes', function () {
    $indexes = getIndexNames('deck_versions');
    expect($indexes)->toContain('deck_versions_deck_id_index');

    $indexes = getIndexNames('decks');
    expect($indexes)->toContain('decks_account_id_index');
    expect($indexes)->toContain('decks_archetype_id_index');

    $indexes = getIndexNames('card_game_stats');
    expect($indexes)->toContain('card_game_stats_game_id_index');

    $indexes = getIndexNames('leagues');
    expect($indexes)->toContain('leagues_deck_version_id_index');

    $indexes = getIndexNames('import_scans');
    expect($indexes)->toContain('import_scans_deck_version_id_index');
});

it('adds filter and compound indexes', function () {
    $indexes = getIndexNames('log_events');
    expect($indexes)->toContain('log_events_processed_at_index');

    $indexes = getIndexNames('matches');
    expect($indexes)->toContain('matches_imported_index');
    expect($indexes)->toContain('matches_state_started_at_index');

    $indexes = getIndexNames('card_game_stats');
    expect($indexes)->toContain('card_game_stats_deck_version_id_oracle_id_index');

    $indexes = getIndexNames('games');
    expect($indexes)->toContain('games_match_id_won_index');
});

it('removes signature index from deck_versions', function () {
    $indexes = getIndexNames('deck_versions');
    expect($indexes)->not->toContain('deck_versions_signature_index');
});
