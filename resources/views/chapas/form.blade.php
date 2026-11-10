<x-app-layout>
    <x-slot name="header"><x-page-heading :back-href="route('chapas.index')">{{ $chapa->exists ? 'Editar chapa' : 'Nova chapa' }}</x-page-heading></x-slot>
    <div class="mx-auto max-w-3xl p-6">
        <x-notice />
        <form method="POST" action="{{ $chapa->exists ? route('chapas.update', $chapa) : route('chapas.store') }}" class="space-y-4 rounded bg-white p-6 shadow">
            @csrf @if($chapa->exists) @method('PUT') @endif
            <label class="block">Material<select id="chapa-material" class="mt-1 block w-full rounded border-gray-300" name="material_id" required><option value="">Selecione</option>@foreach($materiais as $material)<option value="{{ $material->id }}" @selected(old('material_id', $chapa->material_id) == $material->id)>{{ $material->nome }}</option>@endforeach</select></label>
            <label class="block">Espessura<select id="chapa-espessura" class="mt-1 block w-full rounded border-gray-300" name="espessura_id" required><option value="">Selecione</option>@foreach($espessuras as $espessura)<option value="{{ $espessura->id }}" data-material="{{ $espessura->material_id }}" @selected(old('espessura_id', $chapa->espessura_id) == $espessura->id)>{{ $espessura->valor_mm }} mm</option>@endforeach</select></label>
            <label class="block">Largura (mm)<input class="mt-1 block w-full rounded border-gray-300" type="number" step="0.001" min="0.001" name="largura_mm" required value="{{ old('largura_mm', $chapa->largura_mm) }}"></label>
            <label class="block">Altura (mm)<input class="mt-1 block w-full rounded border-gray-300" type="number" step="0.001" min="0.001" name="altura_mm" required value="{{ old('altura_mm', $chapa->altura_mm) }}"></label>
            <p class="text-sm text-slate-500">Cada cadastro adiciona uma chapa ao estoque. Kerf e segurança do corte são configurados na espessura do material.</p>
            <button class="rounded bg-indigo-700 px-4 py-2 text-white">Salvar</button>
        </form>
    </div>
    <script>
        const chapaMaterial = document.getElementById('chapa-material');
        const chapaEspessura = document.getElementById('chapa-espessura');
        function filtrarEspessuras() {
            for (const option of chapaEspessura.options) {
                if (!option.value) continue;
                option.hidden = option.disabled = option.dataset.material !== chapaMaterial.value;
            }
            if (chapaEspessura.selectedOptions[0]?.disabled) chapaEspessura.value = '';
        }
        chapaMaterial.addEventListener('change', filtrarEspessuras);
        filtrarEspessuras();
    </script>
</x-app-layout>
