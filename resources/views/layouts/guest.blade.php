<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'NestLaser') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=nunito-sans:400,500,600,700,800&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        <div class="guest-shell">
            <a href="/" class="guest-brand" aria-label="NestLaser — início"><span class="app-brand-mark" aria-hidden="true"><svg viewBox="0 0 32 32" fill="none"><path d="M5 24V8l11 9V8l11 9v7M5 28h22"/></svg></span>Nest<span class="app-brand-accent">Laser</span></a>
            <div class="guest-card">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
