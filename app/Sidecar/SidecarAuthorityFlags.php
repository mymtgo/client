<?php

namespace App\Sidecar;

use App\Facades\AppSettings;

class SidecarAuthorityFlags
{
    public const FIELDS = ['username', 'on_play', 'game_boundaries', 'game_result', 'match_result'];

    public const DEFAULTS = [
        'username' => true,
        'on_play' => false,
        'game_boundaries' => false,
        'game_result' => false,
        'match_result' => false,
    ];

    /** @return array<string, bool> */
    public static function current(): array
    {
        $flags = self::DEFAULTS;

        foreach (AppSettings::sidecarAuthority() as $key => $value) {
            if (array_key_exists($key, $flags) && is_bool($value)) {
                $flags[$key] = $value;
            }
        }

        return $flags;
    }

    public static function isOn(string $field): bool
    {
        return self::current()[$field] ?? false;
    }

    /**
     * Store a remote or user override. Unknown keys and non-boolean values
     * are dropped so a malformed config can never widen authority.
     *
     * @param  array<string, mixed>  $flags
     */
    public static function applyRemote(array $flags): void
    {
        $clean = [];

        foreach ($flags as $key => $value) {
            if (in_array($key, self::FIELDS, true) && is_bool($value)) {
                $clean[$key] = $value;
            }
        }

        AppSettings::setSidecarAuthority($clean);
    }
}
