<?php

use App\Actions\Archive\WriteRawArchive;
use App\Models\RawArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('gzips the raw segment to the archive disk and indexes it', function () {
    Storage::fake('archive');

    app(WriteRawArchive::class)->run('tok-1', "16:00:00 [INF] line one\n16:00:01 [INF] line two\n");

    $row = RawArchive::where('match_key', 'tok-1')->firstOrFail();
    Storage::disk('archive')->assertExists($row->path);
    expect(gzdecode(Storage::disk('archive')->get($row->path)))->toContain('line two');
    expect($row->byte_len)->toBe(strlen("16:00:00 [INF] line one\n16:00:01 [INF] line two\n"));
});

it('appends a new capture per write (keep-forever, never prunes)', function () {
    Storage::fake('archive');

    app(WriteRawArchive::class)->run('tok-1', 'first capture');
    app(WriteRawArchive::class)->run('tok-1', 'first capture plus more');

    $rows = RawArchive::where('match_key', 'tok-1')->orderBy('id')->get();
    expect($rows)->toHaveCount(2);
    Storage::disk('archive')->assertExists($rows[0]->path);
    Storage::disk('archive')->assertExists($rows[1]->path);
    expect($rows[0]->path)->not->toBe($rows[1]->path);
});

it('skips empty segments', function () {
    Storage::fake('archive');

    app(WriteRawArchive::class)->run('tok-1', '');

    expect(RawArchive::count())->toBe(0);
});
