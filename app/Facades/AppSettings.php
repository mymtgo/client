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
 * @method static bool shouldTransmitMatches()
 * @method static void setShouldTransmitMatches(bool $value)
 * @method static bool isWatcherActive()
 * @method static void setWatcherActive(bool $value)
 * @method static bool isDebugMode()
 * @method static void setDebugMode(bool $value)
 * @method static bool showLeagueWindow()
 * @method static void setShowLeagueWindow(bool $value)
 * @method static bool showOpponentWindow()
 * @method static void setShowOpponentWindow(bool $value)
 * @method static bool showDeckWindow()
 * @method static void setShowDeckWindow(bool $value)
 * @method static bool downloadImagesLocally()
 * @method static void setDownloadImagesLocally(bool $value)
 * @method static bool decksGroupedByArchetype()
 * @method static void setDecksGroupedByArchetype(bool $value)
 * @method static string systemTimezone()
 * @method static void setSystemTimezone(string $tz)
 * @method static ?string deviceId()
 * @method static void setDeviceId(string $id)
 * @method static ?string apiKey()
 * @method static void setApiKey(?string $key)
 * @method static ?string apiKeyExpiresAt()
 * @method static void setApiKeyExpiresAt(?string $expiresAt)
 */
class AppSettings extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \App\Settings\AppSettings::class;
    }
}
