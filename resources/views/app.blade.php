<!DOCTYPE html>
@php
    // The league overlay window is transparent so its card glow is not clipped.
    $transparentPage = ($page['component'] ?? null) === 'leagues/Overlay';
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        @if ($transparentPage)
        html { background-color: transparent; }
        @else
        html {
            background-color: oklch(0.145 0 0);
        }
        @endif
    </style>

    <title data-inertia>{{ config('app.name', 'Laravel') }}</title>

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

    @vite(['resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
    @inertiaHead
</head>
<body class="font-sans antialiased {{ $transparentPage ? 'bg-transparent' : 'texture-bg' }}">
@inertia
</body>
</html>
