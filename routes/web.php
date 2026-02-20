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
    });

    $router->group([
        'prefix' => 'settings',
    ], function (\Illuminate\Routing\Router $group) {
        $group->get('/', \App\Http\Controllers\Settings\IndexController::class)->name('settings.index');
        $group->patch('log-path', \App\Http\Controllers\Settings\UpdateLogPathController::class)->name('settings.log-path');
        $group->patch('data-path', \App\Http\Controllers\Settings\UpdateDataPathController::class)->name('settings.data-path');
        $group->patch('watcher', \App\Http\Controllers\Settings\UpdateWatcherController::class)->name('settings.watcher');
        $group->post('ingest', \App\Http\Controllers\Settings\RunIngestController::class)->name('settings.ingest');
        $group->post('sync', \App\Http\Controllers\Settings\RunSyncController::class)->name('settings.sync');
        $group->post('populate-cards', \App\Http\Controllers\Settings\RunPopulateCardsController::class)->name('settings.populate-cards');
        $group->patch('anonymous-stats', \App\Http\Controllers\Settings\UpdateAnonymousStatsController::class)->name('settings.anonymous-stats');
    });
});
