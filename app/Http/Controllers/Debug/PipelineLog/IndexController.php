<?php

namespace App\Http\Controllers\Debug\PipelineLog;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IndexController extends Controller
{
    public function __invoke(Request $request): Response
    {
        // Discover available log files (newest first)
        $logFiles = collect(glob(storage_path('logs/pipeline-*.log')) ?: [])
            ->map(fn (string $path) => basename($path))
            ->sort()
            ->reverse()
            ->values();

        $file = $request->get('file', $logFiles->first() ?? '');
        $filter = $request->get('filter', '');

        $lines = [];

        $path = storage_path("logs/{$file}");

        if ($file && is_file($path) && str_starts_with($file, 'pipeline-')) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $lines = array_reverse($lines);

            if ($filter !== '') {
                $lines = array_values(array_filter(
                    $lines,
                    fn (string $line) => str_contains(strtolower($line), strtolower($filter))
                ));
            }

            $lines = array_slice($lines, 0, 5000);
        }

        return Inertia::render('debug/PipelineLog', [
            'lines' => $lines,
            'file' => $file,
            'files' => $logFiles,
            'filter' => $filter,
        ]);
    }
}
