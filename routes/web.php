<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/settings', function (Request $request) {
    $request->validate([
        'username' => 'required',
    ]);

    \Native\Desktop\Facades\Settings::set('mtgo_username', $request->input('username'));
});

Route::group([
], function (\Illuminate\Routing\Router $router) {
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
        'prefix' => 'decks',
    ], function (\Illuminate\Routing\Router $group) {
        $group->get('/', \App\Http\Controllers\Decks\IndexController::class)->name('decks.index');
        $group->get('{deck:id}', \App\Http\Controllers\Decks\ShowController::class)->name('decks.show');
    });

});
