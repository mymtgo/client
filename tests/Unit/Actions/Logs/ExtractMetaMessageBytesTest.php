<?php

use App\Actions\Logs\ExtractMetaMessageBytes;

it('extracts MetaMessage byte array when present', function () {
    $raw = 'Message: {"GsMessageMessage":{"MetaMessage":[12,0,0,0,3,1,2,3,4,5,6,7]}}';

    $bytes = ExtractMetaMessageBytes::run($raw);

    expect($bytes)->toBe([12, 0, 0, 0, 3, 1, 2, 3, 4, 5, 6, 7]);
});

it('returns null when raw text has no MetaMessage', function () {
    $raw = 'Message: {"FooMessage":{"Other":"value"}}';

    expect(ExtractMetaMessageBytes::run($raw))->toBeNull();
});

it('returns null on empty input', function () {
    expect(ExtractMetaMessageBytes::run(''))->toBeNull();
});
