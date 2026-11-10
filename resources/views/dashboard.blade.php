<x-app-layout>
    <x-slot name="header"><h1>Visão geral</h1></x-slot>
    <div class="dashboard-page mx-auto max-w-7xl space-y-6 p-6">
        <div class="dashboard-intro">
            <p>Acompanhe o estoque, os pedidos e a produção em um só lugar.</p>
            <a href="{{ route('arranjos.create') }}" class="dashboard-cta"><span aria-hidden="true">＋</span>Criar novo arranjo</a>
        </div>
        <div class="dashboard-stats grid gap-5 md:grid-cols-3">
            <section class="dashboard-stat rounded bg-white p-5 shadow">
                <div class="dashboard-stat-top"><h2>Chapas disponíveis</h2><span class="dashboard-stat-icon dashboard-stat-icon-blue" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m12 3 9 5-9 5-9-5 9-5ZM3 12l9 5 9-5M3 16l9 5 9-5"/></svg></span></div>
                <p class="dashboard-stat-number">{{ $estoque->sum('quantidade') }}</p>
                <p class="dashboard-stat-detail">{{ $estoque->count() }} {{ $estoque->count() === 1 ? 'material em estoque' : 'materiais em estoque' }}</p>
                <div class="dashboard-stat-foot">@foreach($estoque->take(3) as $item)<span>{{ $item->nome }} <strong>{{ $item->quantidade }}</strong></span>@endforeach</div>
            </section>
            <section class="dashboard-stat rounded bg-white p-5 shadow">
                <div class="dashboard-stat-top"><h2>Retalhos disponíveis</h2><span class="dashboard-stat-icon dashboard-stat-icon-green" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 4h16v16H4zM4 13h9v7M13 4v9"/></svg></span></div>
                <p class="dashboard-stat-number">{{ $retalhos->quantidade }}</p>
                <p class="dashboard-stat-detail">{{ number_format((float) $retalhos->area_mm2 / 1000000, 2, ',', '.') }} m² de área estimada</p>
                <a href="{{ route('chapas.index') }}" class="dashboard-stat-link">Ver chapas <span aria-hidden="true">→</span></a>
            </section>
            <section class="dashboard-stat rounded bg-white p-5 shadow">
                <div class="dashboard-stat-top"><h2>Pedidos em andamento</h2><span class="dashboard-stat-icon dashboard-stat-icon-amber" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2h6v2M9 11h6M9 15h6"/></svg></span></div>
                <p class="dashboard-stat-number">{{ $status->sum() }}</p>
                <p class="dashboard-stat-detail">Arranjos em preparação ou produção</p>
                <a href="{{ route('pedidos.index') }}" class="dashboard-stat-link">Ver pedidos <span aria-hidden="true">→</span></a>
            </section>
        </div>
        <div class="dashboard-sections grid gap-5 lg:grid-cols-[1.75fr_1fr]">
            <section class="dashboard-panel overflow-x-auto rounded bg-white p-5 shadow">
                <div class="dashboard-panel-heading"><div><h2>Últimos pedidos</h2><p>Acompanhe os arranjos mais recentes</p></div><a href="{{ route('pedidos.index') }}">Ver todos →</a></div>
                <table class="w-full text-left text-sm"><thead><tr><th>Pedido</th><th>Status</th><th>Material</th><th>Data</th></tr></thead><tbody>
                    @forelse($ultimos as $pedido)<tr class="border-t"><td class="py-3"><a class="dashboard-order-link" href="{{ route('pedidos.show', $pedido) }}">#{{ $pedido->id }}{{ $pedido->nome ? ' · '.$pedido->nome : '' }}</a></td><td><span class="dashboard-status">{{ str_replace('_', ' ', ucfirst($pedido->status->value)) }}</span></td><td>{{ $pedido->chapa?->material?->nome ?? '—' }}</td><td>{{ $pedido->created_at->format('d/m/Y') }}</td></tr>
                    @empty<tr><td colspan="4" class="py-5">Nenhum pedido cadastrado.</td></tr>@endforelse
                </tbody></table>
            </section>
            <section class="dashboard-panel rounded bg-white p-5 shadow">
                <div class="dashboard-panel-heading"><div><h2>Produção concluída</h2><p>Últimos 30 dias</p></div></div>
                @php($maximo = max(1, ...array_column($semanas, 'total')))
                <div class="dashboard-chart mt-5 flex h-48 items-end gap-3 border-b border-gray-300">@foreach($semanas as $semana)<div class="flex flex-1 flex-col items-center gap-1"><span class="text-xs">{{ $semana['total'] }}</span><div class="w-full rounded-t bg-indigo-600" style="height: {{ max(4, $semana['total'] / $maximo * 140) }}px" aria-label="Semana {{ $semana['label'] }}: {{ $semana['total'] }} pedidos"></div></div>@endforeach</div>
                <div class="mt-2 flex gap-3">@foreach($semanas as $semana)<span class="flex-1 text-center text-xs">{{ $semana['label'] }}</span>@endforeach</div>
            </section>
        </div>
        <section class="dashboard-panel dashboard-alert rounded bg-white p-5 shadow">
            <div class="dashboard-panel-heading"><div><h2>Estoque baixo</h2><p>Materiais que precisam de atenção</p></div><a href="{{ route('chapas.index') }}">Ver estoque →</a></div>
            <div class="mt-4 flex flex-wrap gap-2">@forelse($estoque->filter(fn ($item) => $item->quantidade < $item->estoque_minimo_alerta) as $item)<span class="rounded bg-amber-100 px-3 py-2 text-amber-900">{{ $item->nome }}: {{ $item->quantidade }} / mínimo {{ $item->estoque_minimo_alerta }}</span>@empty<span class="text-sm text-gray-500">Tudo certo: nenhum material abaixo do mínimo.</span>@endforelse</div>
        </section>
    </div>
</x-app-layout>
