<x-app-layout>
    <x-slot name="header"><x-page-heading :back-href="route('materiais.index')">Espessuras — {{ $material->nome }}</x-page-heading></x-slot>
    <div class="mx-auto max-w-5xl p-6">
        <x-notice />
        <a class="app-action-primary" href="{{ route('materiais.espessuras.create', $material) }}">＋ Nova espessura</a>
        <div class="mt-4 overflow-x-auto rounded bg-white p-4 shadow">
            <table class="w-full text-left"><thead><tr><th>Espessura</th><th>Velocidade (mm/min)</th><th>Perfuração (s)</th><th>Kerf (mm)</th><th>Folga mínima entre cortes</th><th>Lead-in</th><th>Ações</th></tr></thead><tbody>
            @forelse ($espessuras as $espessura)
                <tr class="border-t"><td class="py-2">{{ $espessura->valor_mm }} mm</td><td>{{ $espessura->velocidade_corte_mm_min }}</td><td>{{ $espessura->tempo_perfuracao_seg }}</td><td>{{ $espessura->kerf_mm ?? '—' }}</td><td>{{ $espessura->espacamento_minimo_mm }} mm</td><td>{{ $espessura->lead_in_mm }} mm</td>
                    <td><a class="underline" href="{{ route('materiais.espessuras.edit', [$material, $espessura]) }}">Editar</a>
                        <form method="POST" class="inline" action="{{ route('materiais.espessuras.destroy', [$material, $espessura]) }}" onsubmit="return confirm('Excluir espessura?')">@csrf @method('DELETE')<button class="text-red-700 underline">Excluir</button></form></td></tr>
            @empty
                <tr><td colspan="7" class="py-4">Nenhuma espessura cadastrada.</td></tr>
            @endforelse
            </tbody></table>
        </div>
    </div>
</x-app-layout>
