<?php

test('the splash page exists and only references assets that ship with the build', function () {
    $splashPath = public_path('splash.html');

    expect(file_exists($splashPath))->toBeTrue();

    $html = file_get_contents($splashPath);

    expect($html)->toContain('src="icon.png"')
        ->and(file_exists(public_path('icon.png')))->toBeTrue();
});

test('the example environment enables the splash screen', function () {
    $env = file_get_contents(base_path('.env.example'));

    expect($env)->toContain('NATIVEPHP_SPLASH_ENABLED=true');
});
