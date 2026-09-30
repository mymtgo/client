<?php

use App\Actions\Cards\DownloadCardImage;
use App\Actions\Settings\ApplyCardImagesPath;
use App\Facades\AppSettings;
use App\Jobs\MoveCardImages;
use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->sandbox = storage_path('framework/testing/card-images-folder');
    File::deleteDirectory($this->sandbox);
    File::ensureDirectoryExists($this->sandbox);

    $this->defaultRoot = $this->sandbox.'/default';
    config([
        'filesystems.disks.cards.root' => $this->defaultRoot,
        'filesystems.disks.cards.default_root' => $this->defaultRoot,
    ]);
    Storage::forgetDisk('cards');
});

afterEach(function () {
    File::deleteDirectory($this->sandbox);
});

it('stores no custom folder by default', function () {
    expect(AppSettings::cardImagesPath())->toBeNull();
});

it('points the cards disk at the configured folder', function () {
    $custom = $this->sandbox.'/custom/mymtgo-card-images';
    File::ensureDirectoryExists($custom);
    AppSettings::setCardImagesPath($custom);

    ApplyCardImagesPath::run();
    Storage::disk('cards')->put('1.jpg', 'img');

    expect(File::exists($custom.'/1.jpg'))->toBeTrue()
        ->and(File::exists($this->defaultRoot.'/1.jpg'))->toBeFalse();
});

it('falls back to the default folder when none is configured', function () {
    AppSettings::setCardImagesPath($this->sandbox.'/custom');
    ApplyCardImagesPath::run();

    AppSettings::setCardImagesPath(null);
    ApplyCardImagesPath::run();
    Storage::disk('cards')->put('1.jpg', 'img');

    expect(File::exists($this->defaultRoot.'/1.jpg'))->toBeTrue();
});

it('leaves an already resolved disk alone when the folder has not changed', function () {
    Storage::fake('cards');
    Storage::disk('cards')->put('faked.jpg', 'img');

    ApplyCardImagesPath::run();

    Storage::disk('cards')->assertExists('faked.jpg');
});

it('serves images from a custom folder at the same url', function () {
    $custom = $this->sandbox.'/custom/mymtgo-card-images';
    File::ensureDirectoryExists($custom);
    AppSettings::setCardImagesPath($custom);
    ApplyCardImagesPath::run();
    Storage::disk('cards')->put('42.jpg', 'custom-bytes');

    expect(Storage::disk('cards')->url('42.jpg'))->toBe('/media/cards/42.jpg');

    $response = $this->get('/media/cards/42.jpg');

    $response->assertOk();
    expect($response->streamedContent())->toBe('custom-bytes');
});

it('re-applies the folder before each queued job', function () {
    $custom = $this->sandbox.'/custom/mymtgo-card-images';
    File::ensureDirectoryExists($custom);

    // Another process (the settings page) changes the folder after this
    // worker booted with the default.
    AppSettings::setCardImagesPath($custom);

    dispatch(function () {
        Storage::disk('cards')->put('from-job.jpg', 'img');
    });

    expect(File::exists($custom.'/from-job.jpg'))->toBeTrue();
});

it('queues a move into a dedicated subfolder of the chosen folder', function () {
    Queue::fake();
    $chosen = $this->sandbox.'/chosen';
    File::ensureDirectoryExists($chosen);

    $this->patch(route('settings.card-images-path'), ['path' => $chosen])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Queue::assertPushed(MoveCardImages::class, fn (MoveCardImages $job) => $job->from === $this->defaultRoot
        && $job->to === $chosen.DIRECTORY_SEPARATOR.MoveCardImages::SUBFOLDER);

    expect(Cache::get(MoveCardImages::MOVING_KEY))->toBeTrue()
        ->and(AppSettings::cardImagesPath())->toBeNull();
});

it('creates a chosen folder that does not exist yet', function () {
    Queue::fake();
    $chosen = $this->sandbox.'/new/nested';

    $this->patch(route('settings.card-images-path'), ['path' => $chosen])
        ->assertSessionHasNoErrors();

    expect(is_dir($chosen))->toBeTrue();
});

it('rejects a folder that is the current folder or inside it', function (string $relative) {
    Queue::fake();
    $current = $this->sandbox.'/custom/'.MoveCardImages::SUBFOLDER;
    File::ensureDirectoryExists($current);
    AppSettings::setCardImagesPath($current);

    $this->patch(route('settings.card-images-path'), ['path' => $this->sandbox.$relative])
        ->assertSessionHasErrors('cardImagesPath');

    Queue::assertNothingPushed();
})->with([
    'same parent' => '/custom',
    'inside current' => '/custom/mymtgo-card-images/deeper',
]);

it('rejects a relative folder', function () {
    Queue::fake();

    $this->patch(route('settings.card-images-path'), ['path' => 'relative/folder'])
        ->assertSessionHasErrors('cardImagesPath');

    Queue::assertNothingPushed();
});

it('rejects a folder that cannot be written to', function () {
    Queue::fake();
    $chosen = $this->sandbox.'/readonly';
    File::ensureDirectoryExists($chosen);
    chmod($chosen, 0555);

    try {
        $this->patch(route('settings.card-images-path'), ['path' => $chosen])
            ->assertSessionHasErrors('cardImagesPath');
    } finally {
        chmod($chosen, 0755);
    }

    Queue::assertNothingPushed();
})->skip(PHP_OS_FAMILY === 'Windows' || (function_exists('posix_getuid') && posix_getuid() === 0), 'chmod does not restrict this user');

it('refuses to start a second move while one is running', function () {
    Queue::fake();
    Cache::forever(MoveCardImages::MOVING_KEY, true);

    $this->patch(route('settings.card-images-path'), ['path' => $this->sandbox.'/chosen'])
        ->assertSessionHasErrors('cardImagesPath');

    Queue::assertNothingPushed();
});

it('queues a move back to the default folder on reset', function () {
    Queue::fake();
    $custom = $this->sandbox.'/custom/'.MoveCardImages::SUBFOLDER;
    File::ensureDirectoryExists($custom);
    AppSettings::setCardImagesPath($custom);

    $this->delete(route('settings.card-images-path.reset'))
        ->assertSessionHasNoErrors();

    Queue::assertPushed(MoveCardImages::class, fn (MoveCardImages $job) => $job->from === $custom
        && $job->to === $this->defaultRoot);
});

it('moves images, then switches the folder, then removes the originals', function () {
    Storage::disk('cards')->put('1.jpg', 'one');
    Storage::disk('cards')->put('1_art.jpg', 'art');
    $to = $this->sandbox.'/chosen/'.MoveCardImages::SUBFOLDER;
    Cache::forever(MoveCardImages::MOVING_KEY, true);

    (new MoveCardImages($this->defaultRoot, $to))->handle();

    expect(File::get($to.'/1.jpg'))->toBe('one')
        ->and(File::get($to.'/1_art.jpg'))->toBe('art')
        ->and(File::exists($this->defaultRoot.'/1.jpg'))->toBeFalse()
        ->and(AppSettings::cardImagesPath())->toBe($to)
        ->and(Storage::disk('cards')->get('1.jpg'))->toBe('one')
        ->and(Cache::has(MoveCardImages::MOVING_KEY))->toBeFalse();
});

it('stores null when moving back to the default folder', function () {
    $custom = $this->sandbox.'/custom/'.MoveCardImages::SUBFOLDER;
    File::ensureDirectoryExists($custom);
    File::put($custom.'/1.jpg', 'one');
    AppSettings::setCardImagesPath($custom);
    ApplyCardImagesPath::run();

    (new MoveCardImages($custom, $this->defaultRoot))->handle();

    expect(AppSettings::cardImagesPath())->toBeNull()
        ->and(File::get($this->defaultRoot.'/1.jpg'))->toBe('one')
        ->and(is_dir($custom))->toBeFalse();
});

it('is safe to run twice', function () {
    Storage::disk('cards')->put('1.jpg', 'one');
    $to = $this->sandbox.'/chosen/'.MoveCardImages::SUBFOLDER;

    (new MoveCardImages($this->defaultRoot, $to))->handle();
    (new MoveCardImages($this->defaultRoot, $to))->handle();

    expect(File::get($to.'/1.jpg'))->toBe('one')
        ->and(AppSettings::cardImagesPath())->toBe($to);
});

it('keeps the old folder and removes partial copies when a copy fails', function () {
    Storage::disk('cards')->put('1.jpg', 'one');
    Storage::disk('cards')->put('2.jpg', 'two');
    $to = $this->sandbox.'/chosen/'.MoveCardImages::SUBFOLDER;
    Cache::forever(MoveCardImages::MOVING_KEY, true);

    chmod($this->defaultRoot.'/2.jpg', 0000);

    try {
        (new MoveCardImages($this->defaultRoot, $to))->handle();
    } finally {
        chmod($this->defaultRoot.'/2.jpg', 0644);
    }

    expect(AppSettings::cardImagesPath())->toBeNull()
        ->and(File::exists($this->defaultRoot.'/1.jpg'))->toBeTrue()
        ->and(File::exists($this->defaultRoot.'/2.jpg'))->toBeTrue()
        ->and(File::allFiles($to))->toBeEmpty()
        ->and(Cache::get(MoveCardImages::ERROR_KEY))->toBeString()
        ->and(Cache::has(MoveCardImages::MOVING_KEY))->toBeFalse();
})->skip(PHP_OS_FAMILY === 'Windows' || (function_exists('posix_getuid') && posix_getuid() === 0), 'chmod does not restrict this user');

it('falls back to the default folder instead of failing when the custom folder is gone', function () {
    AppSettings::setCardImagesPath('/nonexistent/drive/'.MoveCardImages::SUBFOLDER);

    ApplyCardImagesPath::run();

    expect(ApplyCardImagesPath::available())->toBeFalse()
        ->and(Storage::disk('cards')->exists('anything.jpg'))->toBeFalse()
        ->and(Storage::disk('cards')->url('1.jpg'))->toBe('/media/cards/1.jpg');
});

it('skips image downloads while the custom folder is gone', function () {
    Http::fake(['*' => Http::response('bytes')]);
    AppSettings::setCardImagesPath('/nonexistent/drive/'.MoveCardImages::SUBFOLDER);
    ApplyCardImagesPath::run();
    $card = Card::factory()->create(['mtgo_id' => 123, 'image' => 'https://example.test/123.jpg', 'local_image' => null]);

    DownloadCardImage::run($card);

    expect($card->fresh()->local_image)->toBeNull()
        ->and(File::exists($this->defaultRoot.'/123.jpg'))->toBeFalse();
});
