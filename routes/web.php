<?php

use App\Http\Controllers\Settings\BrowseFolderController;
use App\Http\Controllers\Settings\CheckApiStatusController;
use App\Http\Controllers\Settings\ReauthenticateController;
use App\Http\Controllers\Settings\RunIngestController;
use App\Http\Controllers\Settings\UpdateAutostartController;
use App\Http\Controllers\Settings\UpdateDataPathController;
use App\Http\Controllers\Settings\UpdateDebugModeController;
use App\Http\Controllers\Settings\UpdateLogPathController;
use App\Http\Controllers\Settings\UpdateShareStatsController;
use App\Http\Controllers\Settings\UpdateTrustSettingController;
use App\Http\Controllers\Settings\UpdateWatcherController;
use App\Http\Controllers\Support\DownloadReportBundleController;
use App\Http\Controllers\Support\MarkDonationPromptSeenController;
use App\Http\Controllers\Updates\InstallController;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::group([], function (Router $router) {
    // Stub home — the v1 UI is a from-scratch rebuild (client-ui). The old
    // frontend was deleted wholesale; only backend config endpoints survive.
    $router->get('/', fn () => Inertia::render('Blank'))->name('home');

    $router->group(['prefix' => 'settings'], function (Router $group) {
        $group->get('browse-folder', BrowseFolderController::class)->name('settings.browse-folder');
        $group->patch('log-path', UpdateLogPathController::class)->name('settings.log-path');
        $group->patch('data-path', UpdateDataPathController::class)->name('settings.data-path');
        $group->patch('watcher', UpdateWatcherController::class)->name('settings.watcher');
        $group->post('ingest', RunIngestController::class)->name('settings.ingest');
        $group->patch('share-stats', UpdateShareStatsController::class)->name('settings.share-stats');
        $group->patch('card-stats-trust', UpdateTrustSettingController::class)->name('settings.card-stats-trust');
        $group->patch('debug-mode', UpdateDebugModeController::class)->name('settings.debug-mode');
        $group->patch('autostart', UpdateAutostartController::class)->name('settings.autostart');
        $group->get('api-status', CheckApiStatusController::class)->name('settings.api-status');
        $group->post('reauthenticate', ReauthenticateController::class)->name('settings.reauthenticate');
    });

    $router->get('updates/install', InstallController::class)->name('updates.install');

    $router->group(['prefix' => 'support'], function (Router $group) {
        $group->get('report', DownloadReportBundleController::class)->name('support.report.download');
        $group->post('donation/seen', MarkDonationPromptSeenController::class)->name('support.donation.seen');
    });
});
