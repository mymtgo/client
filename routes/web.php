<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('setup', action: function () {
    return Inertia::render('Setup', [
        'username' => fn () => \App\Facades\Mtgo::getUsername(),
        'logs_missing' => fn () => \App\Facades\Mtgo::logFileMissing(),
        'log_data_path' => fn () => \App\Facades\Mtgo::getLogDataPath(),
    ]);
})->name('setup');

Route::post('setup', function () {
    \App\Facades\Mtgo::runInitialSetup();

    return redirect()->to('/');
})->name('setup.configure');

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
    });

    $router->group([
        'prefix' => 'decks',
    ], function (\Illuminate\Routing\Router $group) {
        $group->get('{deck:id}', \App\Http\Controllers\Decks\ShowController::class)->name('decks.show');
    });

});
