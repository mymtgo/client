<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/settings', function (Request $request) {
    $request->validate([
        'username' => 'required',
    ]);

    \Native\Laravel\Facades\Settings::set('mtgo_username', $request->input('username'));
});

Route::group([
], function (\Illuminate\Routing\Router $router) {
    $router->get('/', \App\Http\Controllers\IndexController::class)->name('home');

    $router->group([
        'prefix' => 'matches',
    ], function (\Illuminate\Routing\Router $group) {
        $group->get('{id}', \App\Http\Controllers\Matches\ShowController::class)->name('matches.show');
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
        $group->get('{deck:id}', \App\Http\Controllers\Decks\ShowController::class)->name('decks.show');
    });

});
