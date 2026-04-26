<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Api\CheckApiStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class CheckApiStatusController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(CheckApiStatus::run());
    }
}
