<?php

arch('live pipeline does not import from Import namespace')
    ->expect('App\Actions\Pipeline')
    ->not->toUse('App\Actions\Import');

arch('live pipeline does not import the XML history parser')
    ->expect('App\Actions\Pipeline')
    ->not->toUse('App\Actions\Import\ParseGameHistory');
