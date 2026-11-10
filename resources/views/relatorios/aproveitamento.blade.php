<x-app-layout><x-slot name="header"><h1 class="text-xl font-semibold">Aproveitamento de material</h1></x-slot>
    <div class="mx-auto max-w-6xl space-y-4 p-6">
        <a class="underline" href="{{ route('relatorios.index') }}">← Relatórios</a>
        @include('relatorios._filtros', ['route' => 'relatorios.aproveitamento'])
        <a class="inline-block rounded border border-indigo-700 px-4 py-2 text-indigo-700" href="{{ route('relatorios.aproveitamento.csv', $filters) }}">Exportar CSV</a>
        <div class="overflow-x-auto rounded bg-white p-4 shadow"><table class="w-full text-left"><thead><tr><th>Pedido</th><th>Data</th><th>Chapa</th><th>Material</th><th>Área chapa</th><th>Área peças</th><th>Aproveitamento</th></tr></thead><tbody>
            @forelse($rows as $row)<tr class="border-t"><td class="py-2"><a class="underline" href="{{ route('pedidos.show', $row->id) }}">#{{ $row->id }}</a></td><td>{{ substr($row->concluido_em, 0, 10) }}</td><td>#{{ $row->chapa_id }}</td><td>{{ $row->material }}</td><td>{{ number_format($row->area_chapa_mm2, 1, ',', '.') }} mm²</td><td>{{ number_format($row->area_pecas_mm2, 1, ',', '.') }} mm²</td><td>{{ number_format($row->percentual, 1, ',', '.') }}%</td></tr>
            @empty<tr><td colspan="7" class="py-4">Nenhum pedido no período.</td></tr>@endforelse
        </tbody></table></div>
        <p class="rounded bg-indigo-50 p-4 font-semibold">Média do período: {{ $media === null ? '—' : number_format($media, 1, ',', '.').'%' }}</p>
        {{ $rows->links() }}
    </div>
</x-app-layout>
