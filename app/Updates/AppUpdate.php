<?php

namespace App\Updates;

abstract class AppUpdate
{
    /**
     * The app version this update should run at.
     */
    abstract public function version(): string;

    /**
     * Run the update.
     */
    abstract public function run(): void;
}
