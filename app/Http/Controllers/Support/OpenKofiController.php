<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Native\Desktop\Facades\Shell;

class OpenKofiController extends Controller
{
    private const KOFI_URL = 'https://ko-fi.com/mymtgo';

    public function __invoke(): Response
    {
        Shell::openExternal(self::KOFI_URL);

        return response()->noContent();
    }
}
