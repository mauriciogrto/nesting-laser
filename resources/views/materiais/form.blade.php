<x-app-layout>
    <x-slot name="header"><x-page-heading :back-href="route('materiais.index')">{{ $material->exists ? 'Editar material' : 'Novo material' }}</x-page-heading></x-slot>
    <div class="mx-auto max-w-3xl p-6">
        <x-notice />
        <form class="space-y-4 rounded bg-white p-6 shadow" method="POST" action="{{ $material->exists ? route('materiais.update', $material) : route('materiais.store') }}">
            @csrf @if($material->exists) @method('PUT') @endif
            <label class="block">Nome <input class="mt-1 block w-full rounded border-gray-300" name="nome" required maxlength="255" value="{{ old('nome', $material->nome) }}"></label>
            <label class="block">Densidade (kg/m³) <input class="mt-1 block w-48 rounded border-gray-300" name="densidade_kg_m3" type="number" min="0.01" max="30000" step="0.01" value="{{ old('densidade_kg_m3', $material->densidade_kg_m3) }}" placeholder="Ex.: 7850"><span class="mt-1 block text-xs text-gray-500">Usada para calcular o peso estimado da chapa e das peças no PDF. Deixe em branco se não souber.</span></label>
            <label class="block">Alerta quando houver menos de <input class="mt-1 block w-40 rounded border-gray-300" name="estoque_minimo_alerta" type="number" min="0" required value="{{ old('estoque_minimo_alerta', $material->estoque_minimo_alerta ?? 1) }}"> chapas disponíveis</label>
            <button class="rounded bg-indigo-700 px-4 py-2 text-white">Salvar</button>
        </form>
    </div>
</x-app-layout>
