<x-app-layout>
    <x-slot name="header"><x-page-heading :back-href="route('materiais.espessuras.index', $material)">{{ $espessura->exists ? 'Editar espessura' : 'Nova espessura' }} — {{ $material->nome }}</x-page-heading></x-slot>
    <div class="mx-auto max-w-3xl p-6">
        <x-notice />
        <form class="space-y-4 rounded bg-white p-6 shadow" method="POST" action="{{ $espessura->exists ? route('materiais.espessuras.update', [$material, $espessura]) : route('materiais.espessuras.store', $material) }}">
            @csrf @if($espessura->exists) @method('PUT') @endif
            <label class="block">Espessura (mm)<input class="mt-1 block w-full rounded border-gray-300" type="number" step="0.001" min="0.001" name="valor_mm" required value="{{ old('valor_mm', $espessura->valor_mm) }}"></label>
            <label class="block">Velocidade de corte (mm/min)<input class="mt-1 block w-full rounded border-gray-300" type="number" step="0.01" min="0.01" name="velocidade_corte_mm_min" required value="{{ old('velocidade_corte_mm_min', $espessura->velocidade_corte_mm_min) }}"></label>
            <label class="block">Tempo de perfuração (s)<input class="mt-1 block w-full rounded border-gray-300" type="number" step="0.001" min="0" name="tempo_perfuracao_seg" required value="{{ old('tempo_perfuracao_seg', $espessura->tempo_perfuracao_seg) }}"></label>
            <label class="block">Kerf — largura da ranhura removida (mm)<input class="mt-1 block w-full rounded border-gray-300" type="number" step="0.001" min="0" name="kerf_mm" value="{{ old('kerf_mm', $espessura->kerf_mm) }}"></label>
            <label class="block">Folga térmica mínima entre trajetórias de corte (mm)<input class="mt-1 block w-full rounded border-gray-300" type="number" step="0.001" min="0.001" max="100" name="espacamento_minimo_mm" required value="{{ old('espacamento_minimo_mm', $espessura->espacamento_minimo_mm ?? 10) }}"></label>
            <label class="block">Comprimento da entrada de corte / lead-in (mm)<input class="mt-1 block w-full rounded border-gray-300" type="number" step="0.001" min="0.001" max="100" name="lead_in_mm" required value="{{ old('lead_in_mm', $espessura->lead_in_mm ?? 2) }}"></label>
            <label class="block">Custo da hora de máquina (R$)<input class="mt-1 block w-full rounded border-gray-300" type="number" step="0.01" min="0" name="custo_hora_maquina" value="{{ old('custo_hora_maquina', $espessura->custo_hora_maquina ?? 0) }}"></label>
            <p class="text-sm text-gray-600">A folga térmica nunca pode ser inferior a 10 mm. Ela mede a distância mínima entre as linhas centrais dos cortes e também limita a aproximação à borda da chapa. O material nominal intacto entre duas linhas no mínimo é aproximadamente folga térmica menos kerf. A folga deve superar o kerf; estes valores precisam de medição na máquina.</p>
            <button class="rounded bg-indigo-700 px-4 py-2 text-white">Salvar</button>
        </form>
    </div>
</x-app-layout>
