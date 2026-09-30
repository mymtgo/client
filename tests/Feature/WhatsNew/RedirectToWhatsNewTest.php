<?php

use App\Actions\WhatsNew\WhatsNewContent;
use App\Facades\AppSettings;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->contentPath = sys_get_temp_dir().'/whats-new-'.uniqid().'.md';
    file_put_contents($this->contentPath, '# New');
    WhatsNewContent::usePath($this->contentPath);
    config(['nativephp.version' => '0.45.0']);
});

afterEach(function () {
    @unlink($this->contentPath);
    WhatsNewContent::usePath('/nonexistent/whats-new.md');
});

it('redirects once on a minor bump', function () {
    AppSettings::setWhatsNewSeenVersion('0.44.1');

    $this->get(route('settings.general'))->assertRedirect(route('whats-new'));
    expect(AppSettings::whatsNewSeenVersion())->toBe('0.45.0');

    $this->get(route('settings.general'))->assertOk();
});

it('redirects on a major bump and across skipped versions', function (string $seen, string $running) {
    config(['nativephp.version' => $running]);
    AppSettings::setWhatsNewSeenVersion($seen);

    $this->get(route('settings.general'))->assertRedirect(route('whats-new'));
})->with([
    'major' => ['0.45.0', '1.0.0'],
    'skipped minors' => ['0.41.1', '0.45.2'],
]);

it('does not redirect on a patch bump', function () {
    config(['nativephp.version' => '0.45.1']);
    AppSettings::setWhatsNewSeenVersion('0.45.0');

    $this->get(route('settings.general'))->assertOk();
});

it('seeds the version on a fresh install without redirecting', function () {
    $this->get(route('settings.general'))->assertOk();

    expect(AppSettings::whatsNewSeenVersion())->toBe('0.45.0');
});

it('redirects existing users who have never seen the page', function () {
    MtgoMatch::factory()->create();

    $this->get(route('settings.general'))->assertRedirect(route('whats-new'));
});

it('does not redirect when the file is missing or empty', function (bool $delete) {
    if ($delete) {
        unlink($this->contentPath);
    } else {
        file_put_contents($this->contentPath, "\n");
    }
    AppSettings::setWhatsNewSeenVersion('0.44.0');

    $this->get(route('settings.general'))->assertOk();
    expect(AppSettings::whatsNewSeenVersion())->toBe('0.44.0');
})->with(['missing' => true, 'empty' => false]);

it('does not redirect partial reloads or posts', function () {
    AppSettings::setWhatsNewSeenVersion('0.44.0');

    inertiaPartial(route('settings.general'), 'settings/General', ['update'])->assertOk();
    $this->post(route('settings.check-updates'))->assertRedirect();

    expect(AppSettings::whatsNewSeenVersion())->toBe('0.44.0');
});

it('never redirects overlay windows', function () {
    AppSettings::setWhatsNewSeenVersion('0.44.0');

    $response = $this->get(route('overlay.game'));

    expect($response->isRedirect(route('whats-new')))->toBeFalse()
        ->and(AppSettings::whatsNewSeenVersion())->toBe('0.44.0');
});

it('does not redirect the page to itself', function () {
    AppSettings::setWhatsNewSeenVersion('0.44.0');

    $this->get(route('whats-new'))->assertOk();
});

it('does not redirect in local development', function () {
    // NativePHP copies the repo's storage/ over the dev app data on every
    // dev launch, so the seen version never survives a restart there.
    app()->detectEnvironment(fn () => 'local');
    AppSettings::setWhatsNewSeenVersion('0.44.0');

    $this->get(route('settings.general'))->assertOk();
});
