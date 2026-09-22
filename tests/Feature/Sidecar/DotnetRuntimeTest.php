<?php

use App\Sidecar\DotnetRuntime;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/dotnet-root-'.uniqid();
    mkdir($this->root, 0755, true);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->root));
});

function makeDesktopRuntime(string $root, string $version): void
{
    mkdir($root.'/shared/'.DotnetRuntime::DESKTOP_FRAMEWORK.'/'.$version, 0755, true);
}

it('reports missing when the dotnet root does not exist', function () {
    expect(DotnetRuntime::desktopInstalled([$this->root.'/nope']))->toBeFalse();
});

it('reports missing when only an older desktop runtime is present', function () {
    makeDesktopRuntime($this->root, '8.0.11');
    makeDesktopRuntime($this->root, '9.0.4');

    expect(DotnetRuntime::desktopInstalled([$this->root]))->toBeFalse();
});

it('reports missing when only the base runtime is present', function () {
    mkdir($this->root.'/shared/Microsoft.NETCore.App/10.0.12', 0755, true);

    expect(DotnetRuntime::desktopInstalled([$this->root]))->toBeFalse();
});

it('reports installed for the required major', function () {
    makeDesktopRuntime($this->root, '10.0.3');

    expect(DotnetRuntime::desktopInstalled([$this->root]))->toBeTrue();
});

it('reports installed for a newer major because the exe rolls forward', function () {
    makeDesktopRuntime($this->root, '11.0.0');

    expect(DotnetRuntime::desktopInstalled([$this->root]))->toBeTrue();
});

it('ignores preview folders and stray files', function () {
    makeDesktopRuntime($this->root, '10.0.0-preview.7');
    touch($this->root.'/shared/'.DotnetRuntime::DESKTOP_FRAMEWORK.'/10.0.12');

    expect(DotnetRuntime::desktopInstalled([$this->root]))->toBeFalse();
});

it('finds the runtime in any of the candidate roots', function () {
    $second = $this->root.'/second';
    makeDesktopRuntime($second, '10.0.12');

    expect(DotnetRuntime::desktopInstalled([$this->root.'/first', $second]))->toBeTrue();
});

it('recognises the apphost exit code for a missing runtime in both signed and unsigned form', function () {
    expect(DotnetRuntime::isMissingRuntimeExitCode(-2147450730))->toBeTrue();
    expect(DotnetRuntime::isMissingRuntimeExitCode(2147516566))->toBeTrue();
    expect(DotnetRuntime::isMissingRuntimeExitCode(0))->toBeFalse();
    expect(DotnetRuntime::isMissingRuntimeExitCode(1))->toBeFalse();
});
