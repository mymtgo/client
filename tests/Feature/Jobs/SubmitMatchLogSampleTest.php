<?php

use App\Jobs\SubmitMatchLogSample;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Native\Desktop\Facades\Settings;

beforeEach(function () {
    Settings::set('share_stats', 1);
    Settings::set('device_id', 'test-device');
    Settings::set('api_key', Crypt::encrypt('test-key'));
});

function runSampleJob(array $overrides = []): void
{
    $args = array_merge([
        'matchToken' => '95f4d09f-7d8f-4e14-aafd-1abed0415ea8',
        'matchType' => 'Constructed',
        'format' => 'CMODERN',
        'rawText' => "CurrentStateProcessor=LeagueMatchJoinedEventUnderwayState\nPlayFormatCd=CMODERN\n",
        'username' => 'someone',
    ], $overrides);

    (new SubmitMatchLogSample(
        matchToken: $args['matchToken'],
        matchType: $args['matchType'],
        format: $args['format'],
        rawText: $args['rawText'],
        username: $args['username'],
    ))->handle();
}

it('posts to the API with the correct payload when share_stats is enabled', function () {
    Http::fake([
        '*/api/match-log-samples' => Http::response('', 204),
    ]);

    runSampleJob();

    Http::assertSent(function ($request) {
        return $request->url() === config('mymtgo_api.url').'/api/match-log-samples'
            && $request['match_token'] === '95f4d09f-7d8f-4e14-aafd-1abed0415ea8'
            && $request['match_type'] === 'Constructed'
            && $request['format'] === 'CMODERN'
            && $request['username'] === 'someone'
            && str_contains($request['raw_text'], 'LeagueMatchJoinedEventUnderwayState')
            && $request->header('X-Device-Id')[0] === 'test-device'
            && $request->header('X-Api-Key')[0] === 'test-key';
    });
});

it('no-ops when share_stats is disabled', function () {
    Settings::set('share_stats', 0);
    Http::fake();

    runSampleJob();

    Http::assertNothingSent();
});

it('logs a warning on non-2xx, non-401 response', function () {
    Http::fake([
        '*/api/match-log-samples' => Http::response('server error', 500),
    ]);

    expect(fn () => runSampleJob())->not->toThrow(Throwable::class);
});
