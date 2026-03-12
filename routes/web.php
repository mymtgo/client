<?php

use Illuminate\Support\Facades\Route;

Route::group([], function (\Illuminate\Routing\Router $router) {
    $router->get('/', \App\Http\Controllers\IndexController::class)->name('home');

    $router->group([
        'prefix' => 'matches',
    ], function (\Illuminate\Routing\Router $group) {
        $group->get('{id}', \App\Http\Controllers\Matches\ShowController::class)->name('matches.show');
        $group->patch('{id}/archetype', \App\Http\Controllers\Matches\UpdateArchetypeController::class)->name('matches.update-archetype');
        $group->delete('{id}', \App\Http\Controllers\Matches\DeleteController::class)->name('matches.delete');
    });

    $router->group([
        'prefix' => 'games',
    ], function (\Illuminate\Routing\Router $group) {
        $group->get('{id}', \App\Http\Controllers\Games\ShowController::class)->name('games.show');
    });

    $router->group([
        'prefix' => 'leagues',
    ], function (\Illuminate\Routing\Router $group) {
        $group->get('/', \App\Http\Controllers\Leagues\IndexController::class)->name('leagues.index');
        $group->get('overlay', \App\Http\Controllers\Leagues\OverlayController::class)->name('leagues.overlay');
        $group->get('opponent-scout', \App\Http\Controllers\Leagues\OpponentScoutWindowController::class)->name('leagues.opponent-scout');
        $group->delete('{league}', \App\Http\Controllers\Leagues\AbandonController::class)->name('leagues.abandon');
    });

    $router->group([
        'prefix' => 'opponents',
    ], function (\Illuminate\Routing\Router $group) {
        $group->get('/', \App\Http\Controllers\Opponents\IndexController::class)->name('opponents.index');
    });

    $router->group([
        'prefix' => 'decks',
    ], function (\Illuminate\Routing\Router $group) {
        $group->get('/', \App\Http\Controllers\Decks\IndexController::class)->name('decks.index');
        $group->get('{deck:id}', \App\Http\Controllers\Decks\ShowController::class)->name('decks.show');
        $group->get('{deck:id}/popout', \App\Http\Controllers\Decks\PopoutController::class)->name('decks.popout');
        $group->post('{deck:id}/popout', \App\Http\Controllers\Decks\OpenPopoutController::class)->name('decks.open-popout');
    });

    $router->group([
        'prefix' => 'archetypes',
    ], function (\Illuminate\Routing\Router $group) {
        $group->get('/', \App\Http\Controllers\Archetypes\IndexController::class)->name('archetypes.index');
        $group->get('{archetype}', \App\Http\Controllers\Archetypes\ShowController::class)->name('archetypes.show');
        $group->post('{archetype}/download', \App\Http\Controllers\Archetypes\DownloadDecklistController::class)->name('archetypes.download');
        $group->post('{archetype}/export', \App\Http\Controllers\Archetypes\ExportDekController::class)->name('archetypes.export');
    });

    $router->group([
        'prefix' => 'settings',
    ], function (\Illuminate\Routing\Router $group) {
        $group->get('/', \App\Http\Controllers\Settings\IndexController::class)->name('settings.index');
        $group->get('browse-folder', \App\Http\Controllers\Settings\BrowseFolderController::class)->name('settings.browse-folder');
        $group->patch('log-path', \App\Http\Controllers\Settings\UpdateLogPathController::class)->name('settings.log-path');
        $group->patch('data-path', \App\Http\Controllers\Settings\UpdateDataPathController::class)->name('settings.data-path');
        $group->patch('watcher', \App\Http\Controllers\Settings\UpdateWatcherController::class)->name('settings.watcher');
        $group->post('ingest', \App\Http\Controllers\Settings\RunIngestController::class)->name('settings.ingest');
        $group->post('sync', \App\Http\Controllers\Settings\RunSyncController::class)->name('settings.sync');
        $group->post('populate-cards', \App\Http\Controllers\Settings\RunPopulateCardsController::class)->name('settings.populate-cards');
        $group->patch('anonymous-stats', \App\Http\Controllers\Settings\UpdateAnonymousStatsController::class)->name('settings.anonymous-stats');
        $group->patch('share-stats', \App\Http\Controllers\Settings\UpdateShareStatsController::class)->name('settings.share-stats');
        $group->patch('hide-phantom', \App\Http\Controllers\Settings\UpdateHidePhantomController::class)->name('settings.hide-phantom');
        $group->post('submit-matches', \App\Http\Controllers\Settings\RunSubmitMatchesController::class)->name('settings.submit-matches');
        $group->patch('switch-account', \App\Http\Controllers\Settings\SwitchAccountController::class)->name('settings.switch-account');
        $group->patch('account-tracking', \App\Http\Controllers\Settings\UpdateAccountTrackingController::class)->name('settings.account-tracking');
        $group->post('overlay', \App\Http\Controllers\Settings\UpdateOverlaySettingsController::class)->name('settings.overlay');
        $group->patch('debug-mode', \App\Http\Controllers\Settings\UpdateDebugModeController::class)->name('settings.debug-mode');
    });

    $router->group([
        'prefix' => 'debug',
        'middleware' => 'debug',
    ], function (\Illuminate\Routing\Router $group) {
        // Matches
        $group->get('matches', \App\Http\Controllers\Debug\Matches\IndexController::class)->name('debug.matches.index');
        $group->patch('matches/{match}', \App\Http\Controllers\Debug\Matches\UpdateController::class)->name('debug.matches.update');
        $group->delete('matches/{match}', \App\Http\Controllers\Debug\Matches\DestroyController::class)->name('debug.matches.destroy');
        $group->patch('matches/{match}/restore', \App\Http\Controllers\Debug\Matches\RestoreController::class)->name('debug.matches.restore');
        $group->post('matches/process', \App\Http\Controllers\Debug\Matches\ProcessController::class)->name('debug.matches.process');

        // Games
        $group->get('games', \App\Http\Controllers\Debug\Games\IndexController::class)->name('debug.games.index');
        $group->patch('games/{game}', \App\Http\Controllers\Debug\Games\UpdateController::class)->name('debug.games.update');

        // Log Events
        $group->get('log-events', \App\Http\Controllers\Debug\LogEvents\IndexController::class)->name('debug.log-events.index');
        $group->patch('log-events/{logEvent}', \App\Http\Controllers\Debug\LogEvents\UpdateController::class)->name('debug.log-events.update');
        $group->post('log-events/ingest', \App\Http\Controllers\Debug\LogEvents\IngestController::class)->name('debug.log-events.ingest');

        // Decks
        $group->get('decks', \App\Http\Controllers\Debug\Decks\IndexController::class)->name('debug.decks.index');
        $group->patch('decks/{deck}', \App\Http\Controllers\Debug\Decks\UpdateController::class)->name('debug.decks.update');
        $group->delete('decks/{deck}', \App\Http\Controllers\Debug\Decks\DestroyController::class)->name('debug.decks.destroy');
        $group->patch('decks/{deck}/restore', \App\Http\Controllers\Debug\Decks\RestoreController::class)->name('debug.decks.restore');
        $group->post('decks/sync', \App\Http\Controllers\Debug\Decks\SyncController::class)->name('debug.decks.sync');

        // Deck Versions
        $group->get('deck-versions', \App\Http\Controllers\Debug\DeckVersions\IndexController::class)->name('debug.deck-versions.index');
        $group->patch('deck-versions/{deckVersion}', \App\Http\Controllers\Debug\DeckVersions\UpdateController::class)->name('debug.deck-versions.update');
    });
});
