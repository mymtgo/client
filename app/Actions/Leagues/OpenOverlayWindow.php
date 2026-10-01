<?php

namespace App\Actions\Leagues;

use App\Facades\AppSettings;
use Native\Desktop\Facades\Window;
use Native\Desktop\Windows\Window as WindowInstance;

class OpenOverlayWindow
{
    public const ID = 'overlay';

    /** Card sizes; the window adds PADDING on every side. */
    /** @var array<'full'|'compact', array{0: int, 1: int}> */
    public const SIZES = ['full' => [300, 100], 'compact' => [240, 58]];

    /**
     * Transparent margin around the card. Its glow is a box-shadow drawn
     * outside the card, which a card-sized window would clip.
     */
    public const PADDING = 16;

    public static function run(): void
    {
        if (self::find()) {
            return;
        }

        [$width, $height] = self::windowSize(AppSettings::overlaySize());

        // Compact minimums in both sizes: there is no min-size setter after
        // open, so a full-size minimum would block resizing down to compact.
        Window::open(self::ID)
            ->route('leagues.overlay')
            ->width($width)
            ->height($height)
            ->minWidth(200 + 2 * self::PADDING)
            ->minHeight(50 + 2 * self::PADDING)
            ->transparent()
            ->hasShadow(false)
            ->trafficLightsHidden()
            ->alwaysOnTop(true, 'screen-saver')
            ->frameless()
            ->resizable()
            ->maximizable(false)
            ->fullscreenable(false)
            ->hideMenu()
            ->showDevTools(false)
            ->title('League Overlay');
    }

    /** @return array{0: int, 1: int} window width and height for a card size, padding included */
    public static function windowSize(string $size): array
    {
        [$width, $height] = self::SIZES[$size] ?? self::SIZES['full'];

        return [$width + 2 * self::PADDING, $height + 2 * self::PADDING];
    }

    public static function find(): ?WindowInstance
    {
        return collect(Window::all())->first(fn ($w) => $w->getId() === self::ID);
    }
}
