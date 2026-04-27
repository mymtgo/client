<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Api\CheckApiStatus;
use App\Actions\RegisterDevice;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ReauthenticateController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $registered = RegisterDevice::run();

        if (! $registered) {
            return response()->json([
                'state' => 'unreachable',
                'error' => 'Device registration failed. Check connection or try again.',
            ]);
        }

        return response()->json(CheckApiStatus::run());
    }
}
