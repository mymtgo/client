<?php

use App\Facades\AppSettings;
use App\Jobs\IngestLogs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('dispatches IngestLogs when paths are valid', function () {
    Bus::fake();

    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mtgo-ingest-test-'.uniqid();
    mkdir($tmp.'/Logs', 0777, true);
    mkdir($tmp.'/Data', 0777, true);
    touch($tmp.'/Logs/mtgo.log');
    touch($tmp.'/Data/Match_GameLog_1.dat');

    AppSettings::setLogPath($tmp.'/Logs');
    AppSettings::setLogDataPath($tmp.'/Data');

    $this->post('/settings/ingest')->assertRedirect();

    Bus::assertDispatched(IngestLogs::class);
});

it('does not dispatch when paths are invalid', function () {
    Bus::fake();

    AppSettings::setLogPath('/path/that/does/not/exist');
    AppSettings::setLogDataPath('/another/missing/path');

    $this->post('/settings/ingest')
        ->assertRedirect()
        ->assertSessionHasErrors(['ingest']);

    Bus::assertNotDispatched(IngestLogs::class);
});
