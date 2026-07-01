<?php

use App\Http\Controllers\Decks\PopoutController;
use App\Http\Controllers\Leagues\OpponentScoutWindowController;
use App\Http\Controllers\Leagues\OverlayController;
use App\Http\Controllers\Settings\BrowseFolderController;
use App\Http\Controllers\Settings\CheckApiStatusController;
use App\Http\Controllers\Settings\DeleteOverlayBackgroundController;
use App\Http\Controllers\Settings\ReauthenticateController;
use App\Http\Controllers\Settings\RunIngestController;
use App\Http\Controllers\Settings\UpdateAutostartController;
use App\Http\Controllers\Settings\UpdateDataPathController;
use App\Http\Controllers\Settings\UpdateDebugModeController;
use App\Http\Controllers\Settings\UpdateLogPathController;
use App\Http\Controllers\Settings\UpdateOverlaySettingsController;
use App\Http\Controllers\Settings\UpdateShareStatsController;
use App\Http\Controllers\Settings\UpdateTrustSettingController;
use App\Http\Controllers\Settings\UpdateWatcherController;
use App\Http\Controllers\Settings\UploadOverlayBackgroundController;
use App\Http\Controllers\Support\DownloadReportBundleController;
use App\Http\Controllers\Support\MarkDonationPromptSeenController;
use App\Http\Controllers\Updates\InstallController;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::group([], function (Router $router) {
    // Placeholder home. The v1 dashboard is built in client-ui (Task 5);
    // until then the settings page is the only main-window surface.
    $router->get('/', fn () => Inertia::render('settings/Index'))->name('home');

    // Overlay windows — re-sourced off the ingest agent in client-ui Task 9.
    $router->get('decks/popout', PopoutController::class)->name('decks.popout');
    $router->get('leagues/overlay', OverlayController::class)->name('leagues.overlay');
    $router->get('leagues/opponent-scout', OpponentScoutWindowController::class)->name('leagues.opponent-scout');

    $router->group(['prefix' => 'settings'], function (Router $group) {
        $group->get('browse-folder', BrowseFolderController::class)->name('settings.browse-folder');
        $group->patch('log-path', UpdateLogPathController::class)->name('settings.log-path');
        $group->patch('data-path', UpdateDataPathController::class)->name('settings.data-path');
        $group->patch('watcher', UpdateWatcherController::class)->name('settings.watcher');
        $group->post('ingest', RunIngestController::class)->name('settings.ingest');
        $group->patch('share-stats', UpdateShareStatsController::class)->name('settings.share-stats');
        $group->patch('card-stats-trust', UpdateTrustSettingController::class)->name('settings.card-stats-trust');
        $group->post('overlay', UpdateOverlaySettingsController::class)->name('settings.overlay');
        $group->post('overlay/background', UploadOverlayBackgroundController::class)->name('settings.overlay.background.upload');
        $group->delete('overlay/background', DeleteOverlayBackgroundController::class)->name('settings.overlay.background.delete');
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
