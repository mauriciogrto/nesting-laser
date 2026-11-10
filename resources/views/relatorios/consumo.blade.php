<x-app-layout><x-slot name="header"><h1 class="text-xl font-semibold">Consumo de chapas</h1></x-slot>
    <div class="mx-auto max-w-6xl space-y-4 p-6"><a class="underline" href="{{ route('relatorios.index') }}">← Relatórios</a>
        @include('relatorios._filtros', ['route' => 'relatorios.consumo'])
        <div class="overflow-x-auto rounded bg-white p-4 shadow"><table class="w-full text-left"><thead><tr><th>Material</th><th>Espessura</th><th>Chapas baixadas</th></tr></thead><tbody>
            @forelse($rows as $row)<tr class="border-t"><td class="py-2">{{ $row->material }}</td><td>{{ $row->espessura_mm }} mm</td><td>{{ $row->quantidade }}</td></tr>
            @empty<tr><td colspan="3" class="py-4">Nenhuma baixa no período.</td></tr>@endforelse
        </tbody></table></div>{{ $rows->links() }}
    </div>
</x-app-layout>
