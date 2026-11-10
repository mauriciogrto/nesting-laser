<x-app-layout>
    <x-slot name="header"><x-page-heading :back-href="route('pedidos.index')">Novo pedido de corte</x-page-heading></x-slot>
    <div class="mx-auto max-w-4xl p-6">
        <x-notice />
        <form method="POST" action="{{ route('pedidos.store') }}" class="space-y-5 rounded bg-white p-6 shadow">
            @csrf
            <label class="block">Nome do pedido<input class="mt-1 block w-full rounded border-gray-300" type="text" name="nome" maxlength="120" value="{{ old('nome') }}"></label>
            <label class="block">Chapa
                <select name="chapa_id" required class="mt-1 block w-full rounded border-gray-300"><option value="">Selecione</option>
                    @foreach($chapas as $chapa)
                        <option value="{{ $chapa->id }}" @selected(old('chapa_id') == $chapa->id)>#{{ $chapa->id }} — {{ $chapa->material->nome }} {{ $chapa->espessura->valor_mm }} mm — {{ $chapa->largura_mm }} × {{ $chapa->altura_mm }} mm — {{ $chapa->quantidade_disponivel }} disponíveis</option>
                    @endforeach
                </select>
            </label>
            <div><h2 class="font-semibold">Peças processadas</h2><p class="text-sm text-gray-600">Informe a quantidade de cada peça (0 para não incluir).</p>
                @forelse($pecas as $peca)
                    <label class="mt-2 flex items-center justify-between gap-4 border-b py-2"><span>{{ $peca->nome }} — {{ $peca->largura_bbox_mm }} × {{ $peca->altura_bbox_mm }} mm</span><input type="number" min="0" max="100" class="w-24 rounded border-gray-300" name="pecas[{{ $peca->id }}]" value="{{ old('pecas.'.$peca->id, 0) }}"></label>
                @empty
                    <p class="py-3">Nenhuma peça processada. Envie um DXF e aguarde a extração.</p>
                @endforelse
            </div>
            <label class="flex items-center gap-2"><input type="checkbox" name="usar_tabs" value="1" @checked(old('usar_tabs'))> Usar microjunções (tabs) no contorno externo</label>
            <label class="block">Tamanho da tab (mm)<input class="mt-1 block w-full rounded border-gray-300" type="number" min="0.001" max="100" step="0.001" name="tamanho_tab_mm" value="{{ old('tamanho_tab_mm', 1) }}"></label>
            <button class="rounded bg-indigo-700 px-4 py-2 text-white">Criar rascunho</button>
        </form>
    </div>
</x-app-layout>
