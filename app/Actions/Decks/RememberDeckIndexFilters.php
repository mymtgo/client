<?php

namespace App\Actions\Decks;

use Illuminate\Http\Request;

/**
 * The deck listing's format and archetype filters survive leaving the page.
 *
 * Every deck page links back to the bare listing URL, which used to drop the
 * sidebar selection and land the user on the full grid (or, with Unclassified
 * chosen, an empty one). The request stays authoritative whenever it mentions
 * a filter, including mentioning it as empty, which is how the user clears
 * one; the remembered pair only fills the silence. Search is deliberately not
 * remembered: it is a one-off narrowing, not a place the user works from.
 */
class RememberDeckIndexFilters
{
    public const SESSION_KEY = 'decks.index.filters';

    /**
     * @return array{format: ?string, archetype: string}
     */
    public static function resolve(Request $request, string $archetype): array
    {
        $remembered = $request->session()->get(self::SESSION_KEY, ['format' => null, 'archetype' => '']);

        if ($request->has('format') || $request->has('archetype')) {
            $filters = [
                'format' => $request->filled('format') ? (string) $request->input('format') : null,
                'archetype' => $archetype,
            ];

            $request->session()->put(self::SESSION_KEY, $filters);

            return $filters;
        }

        return $remembered;
    }

    /**
     * A stats tab is a filter choice too: opening one means the archetype is
     * where the user is working.
     */
    public static function put(Request $request, ?string $format, string $archetype): void
    {
        $request->session()->put(self::SESSION_KEY, ['format' => $format, 'archetype' => $archetype]);
    }

    /**
     * Drop a remembered archetype that no longer resolves, so the stale value
     * is not re-applied on the next bare visit.
     */
    public static function forgetArchetype(Request $request): void
    {
        $remembered = $request->session()->get(self::SESSION_KEY);

        if ($remembered !== null) {
            $request->session()->put(self::SESSION_KEY, [...$remembered, 'archetype' => '']);
        }
    }
}
