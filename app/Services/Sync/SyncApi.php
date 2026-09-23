<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Actions\Sync\Auth\EnsureAccessToken;
use App\Actions\Sync\Auth\RefreshAccessToken;
use App\Exceptions\Replays\ReplayShareRefused;
use App\Exceptions\Sync\LimitedRequiresSupporterException;
use App\Exceptions\Sync\SlotLimitException;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The single authenticated HTTP client for the sync endpoints (manifest,
 * blobs, blobs/fetch, decks/slots, decks/{id}). Every call attaches a bearer token from
 * {@see EnsureAccessToken}; a 401 refreshes the token once (via
 * {@see RefreshAccessToken}) and retries once. A second consecutive 401 is
 * routed through RefreshAccessToken again so credential clearing on an
 * invalid refresh token (its invalid_grant path) stays in that one place
 * rather than being duplicated here; if that call instead succeeds, the
 * device is provably still linked, so the persistent 401 is surfaced as an
 * ordinary RuntimeException rather than a NotLinked exception. Any other
 * failed response (429, a 4xx validation error such as 422, or a 5xx) is
 * not retried: it becomes a RuntimeException carrying the status so the
 * caller (the sync run) can stop and try again next schedule, rather than
 * a caller reading ->json() off a failed response and mistaking its empty
 * body for an empty, successful result.
 */
class SyncApi
{
    public function __construct(
        private EnsureAccessToken $ensureAccessToken,
        private RefreshAccessToken $refreshAccessToken,
        private SyncTokens $tokens,
    ) {}

    /**
     * Per-type counts of what the server holds for this account.
     *
     * @return array{match: int, deck: int, league: int}
     */
    public function status(): array
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->get('/api/sync/status'));

        $counts = $response->json('counts') ?? [];

        return [
            'match' => (int) ($counts['match'] ?? 0),
            'deck' => (int) ($counts['deck'] ?? 0),
            'league' => (int) ($counts['league'] ?? 0),
        ];
    }

    /**
     * @param  array<int, string>  $known
     * @param  array<int, array{client_id: string, hash: string, updated_at: string}>  $dirty
     * @return array{upload: array<int, string>, download: array<int, string>, tombstones: array<int, string>, slots: array{limit: int|null, used: int, decks: array<int, array<string, mixed>>}}
     */
    public function manifest(string $type, ?string $since, array $known, array $dirty, bool $last): array
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->post('/api/sync/manifest', [
            'type' => $type,
            'since' => $since,
            'known' => $known,
            'dirty' => $dirty,
            'last' => $last,
        ]));

        return $response->json();
    }

    /**
     * @param  array<int, array{client_id: string, hash: string, updated_at: string, sidecar: array<string, mixed>, gzip: string}>  $entries
     * @return array{stored: array<int, string>, rejected: array<int, array<string, mixed>>}
     */
    public function uploadBlobs(string $type, array $entries): array
    {
        $response = $this->send(function (PendingRequest $request) use ($type, $entries): Response {
            $meta = [];

            foreach (array_values($entries) as $index => $entry) {
                $request = $request->attach(
                    "blobs[{$index}]",
                    $entry['gzip'],
                    "{$entry['client_id']}.json.gz",
                );

                $meta[$index] = [
                    'client_id' => $entry['client_id'],
                    'hash' => $entry['hash'],
                    'updated_at' => $entry['updated_at'],
                    'sidecar' => $entry['sidecar'],
                ];
            }

            // Laravel's multipart body builder only flattens one array
            // level deep, so a directly-nested `meta` array does not come
            // out as `meta[0][client_id]` fields. Flatten it ourselves into
            // scalar, bracket-noted field names before posting.
            return $request->post('/api/sync/blobs', $this->flattenForMultipart([
                'type' => $type,
                'meta' => $meta,
            ]));
        });

        return $response->json();
    }

    /**
     * @param  array<int, string>  $clientIds
     * @return array{blobs: array<int, array{client_id: string, hash: string, data: string}>}
     */
    public function fetchBlobs(string $type, array $clientIds): array
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->post('/api/sync/blobs/fetch', [
            'type' => $type,
            'client_ids' => $clientIds,
        ]));

        return $response->json();
    }

    /**
     * @return array{limit: int|null, used: int, decks: list<array{client_id: string, enabled_at: string, disabled_at: string|null, frees_at: string|null}>}
     */
    public function deckSlots(): array
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->get('/api/sync/decks/slots'));

        return $response->json();
    }

    /**
     * Turns cloud sync on or off for one deck. `kind` is derived from
     * $clientId itself (never accepted as a parameter) so it can never
     * disagree with the id the server checks it against, and is sent
     * whenever known so the server can still catch a forged or corrupted
     * client id. A 422 carrying `error: slot_limit` or
     * `error: limited_requires_supporter` is the failure a caller acts on,
     * so each is surfaced as a typed exception; every other failure throws
     * through send() as usual.
     *
     * @return array{limit: int|null, used: int, decks: list<array{client_id: string, enabled_at: string, disabled_at: string|null, frees_at: string|null}>}
     */
    public function setDeckSync(string $clientId, bool $enabled): array
    {
        $kind = DeckClientId::isLimited($clientId) ? 'limited' : 'constructed';

        $response = $this->send(
            fn (PendingRequest $request): Response => $request->put("/api/sync/decks/{$clientId}", [
                'enabled' => $enabled,
                'kind' => $kind,
            ]),
            passthroughStatuses: [422],
        );

        if ($response->status() === 422 && $response->json('error') === 'slot_limit') {
            throw new SlotLimitException(
                (int) $response->json('limit'),
                (int) $response->json('used'),
                $response->json('frees_at'),
            );
        }

        if ($response->status() === 422 && $response->json('error') === 'limited_requires_supporter') {
            throw new LimitedRequiresSupporterException;
        }

        if ($response->failed()) {
            throw new RuntimeException(
                "Sync API request failed with status {$response->status()}: ".mb_substr($response->body(), 0, 500),
                $response->status(),
            );
        }

        return $response->json();
    }

    /**
     * Shares one match's replay, every game in it; sharing the same match
     * again refreshes it under the same link. The body is gzipped: a match
     * is a megabyte or more of repetitive JSON. The refusals a player can act on (tier, claim,
     * size, shape) come back as ReplayShareRefused; everything else throws
     * through send() as usual.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{uuid: string, url: string}
     */
    public function shareReplay(string $clientMatchKey, int $loginId, array $snapshot): array
    {
        $body = gzencode(json_encode([
            'client_match_key' => $clientMatchKey,
            'login_id' => $loginId,
            'snapshot' => $snapshot,
        ], JSON_THROW_ON_ERROR), 6);

        $response = $this->send(
            fn (PendingRequest $request): Response => $request
                ->withHeaders(['Content-Encoding' => 'gzip'])
                ->withBody($body, 'application/json')
                ->post('/api/replays'),
            passthroughStatuses: [409, 413, 422],
        );

        $reason = match (true) {
            $response->status() === 422 && $response->json('error') === 'replay_share_requires_supporter' => ReplayShareRefused::SUPPORTER,
            $response->status() === 409 => ReplayShareRefused::NOT_CLAIMED,
            $response->status() === 413 => ReplayShareRefused::TOO_LARGE,
            $response->status() === 422 => ReplayShareRefused::INVALID,
            default => null,
        };

        if ($reason !== null) {
            throw new ReplayShareRefused($reason);
        }

        return ['uuid' => (string) $response->json('uuid'), 'url' => (string) $response->json('url')];
    }

    /** Switches a shared link off. A 404 means it is already gone, which is the goal. */
    public function revokeReplay(string $uuid): void
    {
        $this->send(
            fn (PendingRequest $request): Response => $request->delete("/api/replays/{$uuid}"),
            passthroughStatuses: [404],
        );
    }

    /**
     * Runs $call with a valid bearer token, refreshing and retrying once on
     * a 401. Never logs the token itself.
     *
     * @param  Closure(PendingRequest): Response  $call
     * @param  array<int, int>  $passthroughStatuses
     */
    private function send(Closure $call, array $passthroughStatuses = []): Response
    {
        $token = $this->ensureAccessToken->run();
        $response = $call($this->client($token));

        if ($response->status() === 401) {
            $this->refreshAccessToken->run();
            // Read the just-refreshed token straight off SyncTokens rather
            // than calling EnsureAccessToken::run() again: that would
            // re-check expiry and could trigger a redundant second refresh
            // for a short-lived token.
            $token = $this->tokens->accessToken() ?? $token;
            $response = $call($this->client($token));

            if ($response->status() === 401) {
                // A second consecutive 401 despite a just-refreshed token is
                // routed through RefreshAccessToken's own NotLinked handling
                // (which clears stored credentials and throws when the
                // server reports invalid_grant, or rethrows any other
                // failure as-is) instead of duplicating that logic here. If
                // that refresh instead succeeds, the device is provably
                // still linked, so a NotLinked exception would be wrong;
                // treat the persistent 401 as an ordinary request failure.
                $this->refreshAccessToken->run();

                throw new RuntimeException(
                    'Sync API request failed with status 401 after a successful token refresh.',
                    401,
                );
            }
        }

        // Any remaining failure (a 422 validation error included) must throw
        // rather than fall through as an empty "success": a caller reading
        // ->json() off a failed response sees an empty array, which reads
        // as "nothing to upload, nothing to download" instead of the error
        // it actually is.
        if ($response->failed() && ! in_array($response->status(), $passthroughStatuses, true)) {
            $body = mb_substr($response->body(), 0, 500);

            throw new RuntimeException(
                "Sync API request failed with status {$response->status()}: {$body}",
                $response->status(),
            );
        }

        return $response;
    }

    private function client(string $token): PendingRequest
    {
        // mymtgoSync (not mymtgoReference/mymtgoApi): sync authenticates
        // with a Passport bearer token, not a device key, so it must not
        // trigger mymtgoReference's device-key side effects. It still
        // honours offline mode.
        return Http::mymtgoSync()
            ->withToken($token)
            ->acceptJson();
    }

    /**
     * Flattens a nested array into scalar, PHP-bracket-noted field names
     * (e.g. `meta[0][sidecar][format]`), matching what an HTML form would
     * send for the same nested structure, and dropping null leaves.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<string, scalar>
     */
    private function flattenForMultipart(array $data, string $prefix = ''): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $field = $prefix === '' ? (string) $key : "{$prefix}[{$key}]";

            if (is_array($value)) {
                $result += $this->flattenForMultipart($value, $field);
            } elseif ($value !== null) {
                $result[$field] = $value;
            }
        }

        return $result;
    }
}
