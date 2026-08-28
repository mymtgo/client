<?php

namespace App\Actions\Overlay;

use App\Facades\AppSettings;

/**
 * The game overlay's height is the sum of two very different regions: the
 * fixed part (opponent header, or the "enable a section" hint when nothing
 * is on) that should always be fully visible, and the tab area (draw odds,
 * reveals, sideboard) that scrolls internally and so only needs a sensible
 * default. With no tab section enabled the window should hug the fixed part
 * instead of leaving a tall empty pane underneath it.
 */
class ComputeGameOverlayHeight
{
    /** Default height of the scrolling tab region. */
    public const TAB_AREA = 520;

    /** Smallest window that still shows a full opponent header. */
    public const MIN_HEIGHT = 120;

    /**
     * Estimated rendered height of the opponent header before the page has
     * measured it. The page reports the real value once mounted.
     */
    public const OPPONENT_HEADER_ESTIMATE = 120;

    public static function run(bool $hasTabSections, int $fixedHeight): int
    {
        $height = $fixedHeight + ($hasTabSections ? self::TAB_AREA : 0);

        return max(self::MIN_HEIGHT, $height);
    }

    /**
     * Initial height for a window that has not rendered yet, estimated purely
     * from which sections are switched on.
     */
    public static function fromSettings(?int $fixedHeight = null): int
    {
        $fixedHeight ??= AppSettings::overlayShowOpponent() ? self::OPPONENT_HEADER_ESTIMATE : 0;

        return self::run(self::hasTabSections(), $fixedHeight);
    }

    public static function hasTabSections(): bool
    {
        return AppSettings::overlayShowDrawOdds()
            || AppSettings::overlayShowReveals()
            || AppSettings::overlayShowSideboard();
    }
}
