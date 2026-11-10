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
        @php
            $pageTitle = match (true) {
                request()->routeIs('dashboard') => 'Visão geral',
                request()->routeIs('arranjos.*') => 'Otimizar',
                request()->routeIs('pedidos.*') => 'Pedidos',
                request()->routeIs('pecas.*') => 'Peças',
                request()->routeIs('chapas.*') => 'Chapas',
                request()->routeIs('materiais.*', 'espessuras.*') => 'Materiais',
                request()->routeIs('relatorios.*') => 'Relatórios',
                request()->routeIs('usuarios.*') => 'Usuários',
                request()->routeIs('historico.*') => 'Histórico',
                request()->routeIs('movimentacoes.*') => 'Movimentações',
                default => 'Minha conta',
            };
        @endphp
        <div class="app-shell">
            <a class="app-skip-link" href="#conteudo-principal">Ir para o conteúdo</a>
            @include('layouts.navigation')

            <div class="app-content">
                @isset($header)
                    <div class="app-page-heading">{{ $header }}</div>
                @endisset
                <main id="conteudo-principal" class="app-main">{{ $slot }}</main>
            </div>
        </div>
    </body>
</html>
