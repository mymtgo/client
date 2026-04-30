<?php

use App\Facades\AppSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('overlay');
});

it('stores an uploaded image and saves the path in app settings', function () {
    $file = UploadedFile::fake()->image('bg.png', 1200, 400);

    $response = $this->post(route('settings.overlay.background.upload'), [
        'image' => $file,
    ]);

    $response->assertRedirect();

    $stored = AppSettings::overlayBackgroundPath();
    expect($stored)->not->toBeNull();
    Storage::disk('overlay')->assertExists($stored);
});

it('replaces an existing background and removes the old file', function () {
    Storage::disk('overlay')->put('background-old.png', 'old');
    AppSettings::setOverlayBackgroundPath('background-old.png');

    $response = $this->post(route('settings.overlay.background.upload'), [
        'image' => UploadedFile::fake()->image('new.png'),
    ]);

    $response->assertRedirect();

    Storage::disk('overlay')->assertMissing('background-old.png');
    expect(AppSettings::overlayBackgroundPath())->not->toBe('background-old.png');
});

it('rejects non-image uploads', function () {
    $response = $this->post(route('settings.overlay.background.upload'), [
        'image' => UploadedFile::fake()->create('bad.txt', 10, 'text/plain'),
    ]);

    $response->assertSessionHasErrors('image');
    expect(AppSettings::overlayBackgroundPath())->toBeNull();
});

it('deletes the background image and clears the setting', function () {
    Storage::disk('overlay')->put('background-current.png', 'data');
    AppSettings::setOverlayBackgroundPath('background-current.png');

    $response = $this->delete(route('settings.overlay.background.delete'));

    $response->assertRedirect();
    Storage::disk('overlay')->assertMissing('background-current.png');
    expect(AppSettings::overlayBackgroundPath())->toBeNull();
});
