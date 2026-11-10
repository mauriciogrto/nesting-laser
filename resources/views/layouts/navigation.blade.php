@php
    $primaryNavigation = [
        ['Visão geral', 'dashboard', 'dashboard', 'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z'],
        ['Otimizar', 'arranjos.create', 'arranjos.*', 'm12 2 2.4 6.6L21 11l-6.6 2.4L12 20l-2.4-6.6L3 11l6.6-2.4L12 2z'],
        ['Pedidos', 'pedidos.index', 'pedidos.*', 'M6 4h12a2 2 0 0 1 2 2v14H4V6a2 2 0 0 1 2-2zM9 2h6v4H9zM8 11h8M8 15h8'],
        ['Peças', 'pecas.index', 'pecas.*', 'M3 3h8v8H3zM17 3a4 4 0 1 1 0 8 4 4 0 0 1 0-8zM7 14l4 7H3l4-7zM17 14l4 7h-8l4-7z'],
        ['Chapas', 'chapas.index', 'chapas.*', 'm12 3 9 5-9 5-9-5 9-5zM3 12l9 5 9-5M3 16l9 5 9-5'],
        ['Materiais', 'materiais.index', 'materiais.*', 'm12 2 9 5v10l-9 5-9-5V7l9-5zM3 7l9 5 9-5M12 12v10'],
    ];
    $managementNavigation = [
        ['Relatórios', 'relatorios.index', 'relatorios.*', 'M4 20h16M6 17v-6M12 17V4M18 17V9'],
        ['Usuários', 'usuarios.index', 'usuarios.*', 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21v-2a7 7 0 0 1 14 0v2H2zM17 4a4 4 0 0 1 0 7M18 14a6 6 0 0 1 4 6v1'],
        ['Histórico', 'historico.index', 'historico.*', 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM12 7v5l3 2'],
    ];
@endphp

<div x-data="{ menuOpen: false }" class="app-navigation">
    <div x-show="menuOpen" x-cloak @click="menuOpen = false" class="app-sidebar-backdrop lg:hidden"></div>
    <aside class="app-sidebar" :class="menuOpen ? 'is-open' : ''" aria-label="Navegação principal">
        <div class="app-brand">
            <a href="{{ route('dashboard') }}" class="app-brand-link" aria-label="NestLaser — visão geral">
                <span class="app-brand-mark" aria-hidden="true"><svg viewBox="0 0 32 32" fill="none"><path d="M5 24V8l11 9V8l11 9v7M5 28h22"/></svg></span>
                <span>Nest<span class="app-brand-accent">Laser</span></span>
            </a>
            <button type="button" @click="menuOpen = false" class="app-sidebar-close lg:hidden" aria-label="Fechar menu">×</button>
        </div>
        <nav class="app-sidebar-scroll">
            <p class="app-nav-caption">OPERAÇÃO</p>
            @foreach($primaryNavigation as [$label, $route, $active, $icon])
                <a href="{{ route($route) }}" class="app-nav-item {{ request()->routeIs($active) ? 'is-active' : '' }}" @if(request()->routeIs($active)) aria-current="page" @endif>
                    <svg class="app-nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $icon }}"/></svg><span>{{ $label }}</span>
                </a>
            @endforeach
            @can('admin')
                <div class="app-nav-divider"></div><p class="app-nav-caption">GESTÃO</p>
                @foreach($managementNavigation as [$label, $route, $active, $icon])
                    <a href="{{ route($route) }}" class="app-nav-item {{ request()->routeIs($active) ? 'is-active' : '' }}" @if(request()->routeIs($active)) aria-current="page" @endif>
                        <svg class="app-nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $icon }}"/></svg><span>{{ $label }}</span>
                    </a>
                @endforeach
            @endcan
            <div class="app-nav-divider"></div>
            <a href="{{ route('movimentacoes.index') }}" class="app-nav-item {{ request()->routeIs('movimentacoes.*') ? 'is-active' : '' }}" @if(request()->routeIs('movimentacoes.*')) aria-current="page" @endif>
                <svg class="app-nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7h18M7 3 3 7l4 4M21 17H3m14-4 4 4-4 4"/></svg><span>Movimentações</span>
            </a>
        </nav>
        <div class="app-sidebar-footer"><span class="app-sidebar-footer-dot"></span>Sistema local de corte</div>
    </aside>

    <header class="app-topbar">
        <button type="button" @click="menuOpen = true" class="app-menu-toggle lg:hidden" aria-label="Abrir menu" :aria-expanded="menuOpen.toString()"><svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
        <div class="app-topbar-context"><span class="app-topbar-eyebrow">NESTLASER / PAINEL</span><span class="app-topbar-title">{{ $pageTitle ?? 'Visão geral' }}</span></div>
        <div class="app-topbar-actions">
            <a href="{{ route('arranjos.create') }}" class="app-topbar-create"><span aria-hidden="true">＋</span>Novo arranjo</a>
            <div class="app-profile" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                <button type="button" @click="open = !open" :aria-expanded="open.toString()" aria-haspopup="true" class="app-profile-trigger">
                    <span class="app-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(Auth::user()->name, 0, 1)) }}</span>
                    <span class="app-profile-name">{{ Auth::user()->name }}</span>
                    <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m7 10 5 5 5-5"/></svg>
                </button>
                <div x-show="open" x-cloak x-transition class="app-profile-menu">
                    <p class="app-profile-email">{{ Auth::user()->email }}</p>
                    <a href="{{ route('profile.edit') }}">Meu perfil</a>
                    <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">Sair</button></form>
                </div>
            </div>
        </div>
    </header>
</div>
