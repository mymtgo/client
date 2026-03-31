<?php

namespace App\Updates;

abstract class AppUpdate
{
    /**
     * Run the update.
     */
    abstract public function run(): void;
}
