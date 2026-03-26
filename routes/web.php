<?php

use App\Http\Controllers\Archetypes\DownloadDecklistController;
use App\Http\Controllers\Archetypes\ExportDekController;
use App\Http\Controllers\Debug\Cards\PopulateController;
use App\Http\Controllers\Debug\Decks\SyncController;
use App\Http\Controllers\Debug\LogEvents\IngestController;
use App\Http\Controllers\Debug\Matches\DestroyController;
use App\Http\Controllers\Debug\Matches\ProcessController;
use App\Http\Controllers\Debug\Matches\RestoreController;
use App\Http\Controllers\Debug\Matches\UpdateController;
use App\Http\Controllers\Decks\CardStatsController;
use App\Http\Controllers\Decks\DashboardController;
use App\Http\Controllers\Decks\DecklistController;
use App\Http\Controllers\Decks\LeaguesController;
use App\Http\Controllers\Decks\MatchesController;
use App\Http\Controllers\Decks\MatchupsController;
use App\Http\Controllers\Decks\OpenPopoutController;
use App\Http\Controllers\Decks\PopoutController;
use App\Http\Controllers\IndexController;
use App\Http\Controllers\Leagues\AbandonController;
use App\Http\Controllers\Leagues\OpponentScoutWindowController;
use App\Http\Controllers\Leagues\OverlayController;
use App\Http\Controllers\Matches\DeleteController;
use App\Http\Controllers\Matches\ShowController;
use App\Http\Controllers\Matches\UpdateArchetypeController;
use App\Http\Controllers\Matches\UpdateNotesController;
use App\Http\Controllers\Settings\BrowseFolderController;
use App\Http\Controllers\Settings\RunIngestController;
use App\Http\Controllers\Settings\RunPopulateCardsController;
use App\Http\Controllers\Settings\RunSubmitMatchesController;
use App\Http\Controllers\Settings\RunSyncController;
use App\Http\Controllers\Settings\SwitchAccountController;
use App\Http\Controllers\Settings\UpdateAccountTrackingController;
use App\Http\Controllers\Settings\UpdateAnonymousStatsController;
use App\Http\Controllers\Settings\UpdateDataPathController;
use App\Http\Controllers\Settings\UpdateDebugModeController;
use App\Http\Controllers\Settings\UpdateHidePhantomController;
use App\Http\Controllers\Settings\UpdateLogPathController;
use App\Http\Controllers\Settings\UpdateOverlaySettingsController;
use App\Http\Controllers\Settings\UpdateShareStatsController;
use App\Http\Controllers\Settings\UpdateTimezoneController;
use App\Http\Controllers\Settings\UpdateWatcherController;
use App\Http\Controllers\Updates\InstallController;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

Route::group([], function (Router $router) {
    $router->get('/', IndexController::class)->name('home');

    $router->group([
        'prefix' => 'matches',
    ], function (Router $group) {
        $group->get('{id}', ShowController::class)->name('matches.show');
        $group->patch('{id}/archetype', UpdateArchetypeController::class)->name('matches.update-archetype');
        $group->patch('{id}/notes', UpdateNotesController::class)->name('matches.update-notes');
        $group->delete('{id}', DeleteController::class)->name('matches.delete');
    });

    $router->group([
        'prefix' => 'games',
    ], function (Router $group) {
        $group->get('{id}', App\Http\Controllers\Games\ShowController::class)->name('games.show');
    });

    $router->group([
        'prefix' => 'leagues',
    ], function (Router $group) {
        $group->get('/', App\Http\Controllers\Leagues\IndexController::class)->name('leagues.index');
        $group->get('overlay', OverlayController::class)->name('leagues.overlay');
        $group->get('opponent-scout', OpponentScoutWindowController::class)->name('leagues.opponent-scout');
        $group->delete('{league}', AbandonController::class)->name('leagues.abandon');
    });

    $router->group([
        'prefix' => 'opponents',
    ], function (Router $group) {
        $group->get('/', App\Http\Controllers\Opponents\IndexController::class)->name('opponents.index');
    });

    $router->group([
        'prefix' => 'decks',
    ], function (Router $group) {
        $group->get('/', App\Http\Controllers\Decks\IndexController::class)->name('decks.index');
        $group->get('{deck:id}', DashboardController::class)->name('decks.show');
        $group->get('{deck:id}/card-stats', CardStatsController::class)->name('decks.card-stats');
        $group->get('{deck:id}/matches', MatchesController::class)->name('decks.matches');
        $group->get('{deck:id}/leagues', LeaguesController::class)->name('decks.leagues');
        $group->get('{deck:id}/matchups', MatchupsController::class)->name('decks.matchups');
        $group->get('{deck:id}/decklist', DecklistController::class)->name('decks.decklist');
        $group->get('{deck:id}/popout', PopoutController::class)->name('decks.popout');
        $group->post('{deck:id}/popout', OpenPopoutController::class)->name('decks.open-popout');
    });

    $router->group([
        'prefix' => 'archetypes',
    ], function (Router $group) {
        $group->get('/', App\Http\Controllers\Archetypes\IndexController::class)->name('archetypes.index');
        $group->get('{archetype}', App\Http\Controllers\Archetypes\ShowController::class)->name('archetypes.show');
        $group->post('{archetype}/download', DownloadDecklistController::class)->name('archetypes.download');
        $group->post('{archetype}/export', ExportDekController::class)->name('archetypes.export');
    });

    $router->group([
        'prefix' => 'settings',
    ], function (Router $group) {
        $group->get('/', App\Http\Controllers\Settings\IndexController::class)->name('settings.index');
        $group->get('browse-folder', BrowseFolderController::class)->name('settings.browse-folder');
        $group->patch('log-path', UpdateLogPathController::class)->name('settings.log-path');
        $group->patch('data-path', UpdateDataPathController::class)->name('settings.data-path');
        $group->patch('watcher', UpdateWatcherController::class)->name('settings.watcher');
        $group->post('ingest', RunIngestController::class)->name('settings.ingest');
        $group->post('sync', RunSyncController::class)->name('settings.sync');
        $group->post('populate-cards', RunPopulateCardsController::class)->name('settings.populate-cards');
        $group->patch('anonymous-stats', UpdateAnonymousStatsController::class)->name('settings.anonymous-stats');
        $group->patch('share-stats', UpdateShareStatsController::class)->name('settings.share-stats');
        $group->patch('hide-phantom', UpdateHidePhantomController::class)->name('settings.hide-phantom');
        $group->post('submit-matches', RunSubmitMatchesController::class)->name('settings.submit-matches');
        $group->patch('switch-account', SwitchAccountController::class)->name('settings.switch-account');
        $group->patch('account-tracking', UpdateAccountTrackingController::class)->name('settings.account-tracking');
        $group->post('overlay', UpdateOverlaySettingsController::class)->name('settings.overlay');
        $group->patch('debug-mode', UpdateDebugModeController::class)->name('settings.debug-mode');
        $group->patch('timezone', UpdateTimezoneController::class)->name('settings.timezone');
    });

    $router->get('updates/install', InstallController::class)->name('updates.install');

    $router->group([
        'prefix' => 'debug',
        'middleware' => 'debug',
    ], function (Router $group) {
        // Matches
        $group->get('matches', App\Http\Controllers\Debug\Matches\IndexController::class)->name('debug.matches.index');
        $group->patch('matches/{match}', UpdateController::class)->name('debug.matches.update');
        $group->delete('matches/{match}', DestroyController::class)->name('debug.matches.destroy');
        $group->patch('matches/{match}/restore', RestoreController::class)->name('debug.matches.restore');
        $group->post('matches/process', ProcessController::class)->name('debug.matches.process');

        // Games
        $group->get('games', App\Http\Controllers\Debug\Games\IndexController::class)->name('debug.games.index');
        $group->patch('games/{game}', App\Http\Controllers\Debug\Games\UpdateController::class)->name('debug.games.update');

        // Log Events
        $group->get('log-events', App\Http\Controllers\Debug\LogEvents\IndexController::class)->name('debug.log-events.index');
        $group->patch('log-events/{logEvent}', App\Http\Controllers\Debug\LogEvents\UpdateController::class)->name('debug.log-events.update');
        $group->post('log-events/ingest', IngestController::class)->name('debug.log-events.ingest');

        // Decks
        $group->get('decks', App\Http\Controllers\Debug\Decks\IndexController::class)->name('debug.decks.index');
        $group->patch('decks/{deck}', App\Http\Controllers\Debug\Decks\UpdateController::class)->name('debug.decks.update');
        $group->delete('decks/{deck}', App\Http\Controllers\Debug\Decks\DestroyController::class)->name('debug.decks.destroy');
        $group->patch('decks/{deck}/restore', App\Http\Controllers\Debug\Decks\RestoreController::class)->name('debug.decks.restore');
        $group->post('decks/sync', SyncController::class)->name('debug.decks.sync');

        // Deck Versions
        $group->get('deck-versions', App\Http\Controllers\Debug\DeckVersions\IndexController::class)->name('debug.deck-versions.index');
        $group->patch('deck-versions/{deckVersion}', App\Http\Controllers\Debug\DeckVersions\UpdateController::class)->name('debug.deck-versions.update');

        // Cards
        $group->get('cards', App\Http\Controllers\Debug\Cards\IndexController::class)->name('debug.cards.index');
        $group->post('cards/populate', PopulateController::class)->name('debug.cards.populate');

        // Log Cursors
        $group->get('log-cursors', App\Http\Controllers\Debug\LogCursors\IndexController::class)->name('debug.log-cursors.index');

        // Pipeline Log
        $group->get('pipeline-log', App\Http\Controllers\Debug\PipelineLog\IndexController::class)->name('debug.pipeline-log.index');
    });
});
