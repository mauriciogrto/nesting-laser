<x-app-layout><x-slot name="header"><h1 class="text-xl font-semibold">Tempo de corte: estimado × real</h1></x-slot>
    <div class="mx-auto max-w-6xl space-y-4 p-6"><a class="underline" href="{{ route('relatorios.index') }}">← Relatórios</a>
        @include('relatorios._filtros', ['route' => 'relatorios.tempos'])
        <div class="overflow-x-auto rounded bg-white p-4 shadow"><table class="w-full text-left"><thead><tr><th>Pedido</th><th>Data</th><th>Material</th><th>Estimado</th><th>Real</th><th>Diferença</th></tr></thead><tbody>
            @forelse($rows as $row)<tr class="border-t"><td class="py-2"><a class="underline" href="{{ route('pedidos.show', $row->id) }}">#{{ $row->id }}</a></td><td>{{ substr($row->concluido_em, 0, 10) }}</td><td>{{ $row->material }}</td><td>{{ $row->tempo_corte_estimado_seg ?? '—' }} s</td><td>{{ $row->tempo_corte_real_seg ?? '—' }} s</td><td>{{ $row->diferenca_pct === null ? '—' : number_format($row->diferenca_pct, 1, ',', '.').'%' }}</td></tr>
            @empty<tr><td colspan="6" class="py-4">Nenhum pedido no período.</td></tr>@endforelse
        </tbody></table></div>{{ $rows->links() }}
    </div>
</x-app-layout>
