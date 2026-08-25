<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed get(string $key, mixed $default = null)
 * @method static void set(string $key, mixed $value)
 * @method static void forget(string $key)
 * @method static string logPath()
 * @method static void setLogPath(string $path)
 * @method static string logDataPath()
 * @method static void setLogDataPath(string $path)
 * @method static bool isOffline()
 * @method static void setOffline(bool $value)
 * @method static ?string offlineModeLockedUntil()
 * @method static void setOfflineModeLockedUntil(?string $isoTimestamp)
 * @method static bool isOfflineModeLocked()
 * @method static bool offlineModeNeverSet()
 * @method static bool isWatcherActive()
 * @method static void setWatcherActive(bool $value)
 * @method static bool isDebugMode()
 * @method static void setDebugMode(bool $value)
 * @method static bool showLeagueWindow()
 * @method static void setShowLeagueWindow(bool $value)
 * @method static string|null appServerUrl()
 * @method static void setAppServerUrl(string $url)
 * @method static bool showGameOverlay()
 * @method static void setShowGameOverlay(bool $value)
 * @method static bool overlayShowOpponent()
 * @method static void setOverlayShowOpponent(bool $value)
 * @method static bool overlayShowDrawOdds()
 * @method static void setOverlayShowDrawOdds(bool $value)
 * @method static bool overlayShowReveals()
 * @method static void setOverlayShowReveals(bool $value)
 * @method static bool overlayShowSideboard()
 * @method static void setOverlayShowSideboard(bool $value)
 * @method static bool downloadImagesLocally()
 * @method static void setDownloadImagesLocally(bool $value)
 * @method static ?string overlayBackgroundPath()
 * @method static void setOverlayBackgroundPath(?string $path)
 * @method static bool decksGroupedByArchetype()
 * @method static void setDecksGroupedByArchetype(bool $value)
 * @method static bool hideArchivedDecks()
 * @method static void setHideArchivedDecks(bool $value)
 * @method static int cardStatsTrust()
 * @method static void setCardStatsTrust(int $value)
 * @method static bool autostartEnabled()
 * @method static void setAutostartEnabled(bool $value)
 * @method static string systemTimezone()
 * @method static void setSystemTimezone(string $tz)
 * @method static ?string deviceId()
 * @method static void setDeviceId(string $id)
 * @method static ?string apiKey()
 * @method static void setApiKey(?string $key)
 * @method static ?string apiKeyExpiresAt()
 * @method static void setApiKeyExpiresAt(?string $expiresAt)
 * @method static bool donationPromptSeen()
 * @method static void setDonationPromptSeen(bool $value)
 * @method static ?string archetypesLastRefreshedAt()
 * @method static void setArchetypesLastRefreshedAt(string $value)
 * @method static bool archetypesRefreshInProgress()
 * @method static void setArchetypesRefreshInProgress(bool $value)
 */
class AppSettings extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \App\Settings\AppSettings::class;
    }
}
