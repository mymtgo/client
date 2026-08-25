<?php

namespace App\Enums;

enum DraftState: string
{
    case Connecting = 'connecting';
    case Picking = 'picking';
    case Finished = 'finished';
    case Abandoned = 'abandoned';
}
