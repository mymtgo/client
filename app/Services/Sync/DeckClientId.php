<?php

declare(strict_types=1);

namespace App\Services\Sync;

class DeckClientId
{
    /**
     * decks.mtgo_id is the deck's cross-device identity, but limited pools
     * use `limited:{key}` and the colon fails the server's client-id rule
     * (`[A-Za-z0-9][A-Za-z0-9_-]{0,190}`). The mapping must stay
     * deterministic across devices, so every disallowed byte becomes an
     * underscore instead of being dropped. Collisions are impossible with
     * the shapes in use: constructed ids are purely numeric and never
     * contain an underscore. Identity round-trips through the bundle's own
     * `mtgo_id` field, not the client id, so the import side needs no
     * inverse.
     */
    public static function for(string $mtgoId): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '_', $mtgoId);
    }

    /**
     * Whether $clientId (already sanitised by {@see for()}) names a limited
     * deck. Mirrors the API's own `LimitedClientId::isLimited()` check on
     * the one signal both sides agree on: `for()` turns a limited deck's
     * `limited:{key}` mtgo id into `limited_{key}`, a prefix a purely
     * numeric constructed id can never produce.
     */
    public static function isLimited(string $clientId): bool
    {
        return str_starts_with($clientId, 'limited_');
    }
}
