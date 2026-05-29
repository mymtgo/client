<?php

namespace App\Http\Controllers\Debug\Matches;

use App\Http\Controllers\Controller;
use App\Jobs\RunPipelineJob;
use Illuminate\Http\RedirectResponse;

class ProcessController extends Controller
{
    /**
     * Queue the pipeline run instead of executing it inline. Running it in
     * the web request was hitting PHP-CGI's 30s max_execution_time on users
     * with a backlog, fatal-erroring before back() could redirect.
     */
    public function __invoke(): RedirectResponse
    {
        RunPipelineJob::dispatch();

        return back();
    }
}
