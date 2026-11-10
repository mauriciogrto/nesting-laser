<x-app-layout>
    <x-slot name="header"><x-page-heading :back-href="($preview['pedido_id'] ?? null) ? route('pedidos.show', $preview['pedido_id']) : route('arranjos.create')">Otimizar · {{ $preview['nome'] ?? 'Arranjo em edição' }}</x-page-heading></x-slot>
    @php($posicionadas = array_sum(array_map(fn ($plano) => count($plano['resultado']['pecas_posicionadas']), $preview['planos'])))
    @php($total = array_sum(array_column($preview['pecas'], 'quantidade')))
    @php($tempoTotal = array_sum(array_column($preview['planos'], 'tempo_corte_estimado_seg')))
    <div class="mx-auto max-w-7xl space-y-5 p-6" x-data="{ mensagem: '', salvamento: '' }" @editor-mensagem.window="mensagem = $event.detail" @editor-salvamento.window="salvamento = $event.detail">
        <x-notice />
        <p class="text-sm text-gray-600">O preview mostra os trajetos do CNC: vermelho para corte, amarelo para entrada/saída e verde para trechos não cortados das tabs. Clique numa peça e use + para ampliar perto dela; a roda do mouse também amplia. Use Alt + arrastar para mover a vista ampliada. Na chapa inteira, a folga em milímetros pode ser menor que um pixel. Arraste ou gire as peças; Desfazer e Refazer (Ctrl+Z / Ctrl+Y) revertem as alterações. O arranjo temporário expira após 15 minutos.</p>
        <p x-show="mensagem" x-text="mensagem" class="rounded bg-yellow-50 p-3 text-yellow-900" role="status"></p>
        <div class="flex flex-wrap items-center justify-between gap-3 rounded bg-white px-4 py-3 shadow"><p><strong>Chapa {{ $indice + 1 }} · #{{ $chapa->id }}:</strong> {{ $chapa->material->nome }} · {{ $chapa->espessura->valor_mm }} mm · {{ ucfirst($chapa->origem->value) }} · {{ $chapa->largura_mm }} × {{ $chapa->altura_mm }} mm</p><a class="app-action-secondary" href="#acoes-arranjo">Organizar / exportar ↓</a></div>
        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_16rem]">
            <div id="nesting-canvas" class="relative h-[52vh] min-h-[360px] max-h-[480px] w-full overflow-hidden rounded border bg-white shadow" aria-label="Editor do arranjo na chapa"></div>
            <div class="space-y-4 rounded bg-white p-4 shadow">
                <h2 class="font-semibold">Ordem de corte · chapa {{ $indice + 1 }}</h2>
                <ol id="cut-order" class="max-h-80 space-y-2 overflow-y-auto text-sm"></ol>
                <div class="flex gap-2">
                    <button id="desfazer-arranjo" type="button" class="app-undo-button rounded border px-3 py-1 text-sm disabled:opacity-40" disabled>↶ Desfazer</button>
                    <button id="refazer-arranjo" type="button" class="app-undo-button rounded border px-3 py-1 text-sm disabled:opacity-40" disabled>↷ Refazer</button>
                </div>
                <p>Tempo nesta chapa: <strong><span id="tempo-chapa">{{ $plano['tempo_corte_estimado_seg'] }}</span> s</strong></p>
                <p>Área dos contornos das peças: <strong><span id="area-chapa">{{ number_format((float) $plano['resultado']['area_utilizada_pct'], 2, ',', '.') }}</span>%</strong></p>
                <p x-text="salvamento" class="text-sm text-indigo-700" role="status" aria-live="polite"></p>
            </div>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <section class="rounded bg-white p-4 shadow"><h2 class="text-sm text-gray-500">Tempo estimado do lote</h2><p class="text-2xl font-bold"><span id="tempo-lote">{{ $tempoTotal }}</span> s</p></section>
            <section class="rounded bg-white p-4 shadow"><h2 class="text-sm text-gray-500">Peças posicionadas</h2><p class="text-2xl font-bold">{{ $posicionadas }} / {{ $total }}</p></section>
        </div>
        <div class="flex flex-wrap gap-2" aria-label="Chapas do lote">
            @foreach($preview['planos'] as $numero => $item)
                <a href="{{ route('arranjos.show', ['token' => $token, 'plano' => $numero]) }}" class="rounded px-4 py-2 {{ $numero === $indice ? 'bg-indigo-700 text-white' : 'bg-white text-indigo-700' }}">Chapa {{ $numero + 1 }} · #{{ $item['chapa_id'] }} · {{ count($item['resultado']['pecas_posicionadas']) }} peças</a>
            @endforeach
        </div>
        @if($preview['pecas_nao_posicionadas'])
            <section class="rounded border border-amber-300 bg-amber-50 p-4"><h2 class="font-semibold text-amber-900">Peças não posicionadas</h2>
                <ul class="mt-2 list-disc pl-5 text-amber-900">@foreach(collect($preview['pecas_nao_posicionadas'])->countBy('id') as $id => $quantidade)<li>{{ $names[$id] ?? $id }} × {{ $quantidade }}</li>@endforeach</ul>
                <p class="mt-2 text-sm text-amber-900">Cadastre mais chapas compatíveis e recalcule o lote antes de aceitar.</p>
            </section>
        @endif
        <div id="acoes-arranjo" class="grid gap-4 rounded bg-white p-5 shadow md:grid-cols-2">
            <form method="POST" action="{{ route('arranjos.chapa', $token) }}" class="space-y-3">@csrf
                <label class="block font-semibold">{{ ($preview['pedido_id'] ?? null) ? 'Reorganizar o lote nas chapas reservadas' : 'Organizar automaticamente começando por esta chapa' }}
                    @if($preview['pedido_id'] ?? null)<input type="hidden" name="chapa_id" value="{{ $preview['planos'][0]['chapa_id'] }}">
                    <span class="mt-1 block text-sm font-normal">As chapas do pedido serão usadas primeiro; se necessário, o sistema procurará outra chapa disponível.</span>
                    @else<select name="chapa_id" class="mt-1 block w-full rounded border-gray-300" required>
                        @foreach($chapas as $outra)<option value="{{ $outra->id }}" @selected($outra->id === $chapa->id)>#{{ $outra->id }} · {{ ucfirst($outra->origem->value) }} · {{ $outra->largura_mm }} × {{ $outra->altura_mm }} mm</option>@endforeach
                    </select>@endif
                </label>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="text-sm">Largura do corte (kerf), mm
                        <input class="mt-1 block w-full rounded border-gray-300" type="number" name="kerf_mm" min="0" max="20" step="0.001" value="{{ $preview['kerf_mm'] ?? $chapa->espessura->kerf_mm }}" required>
                    </label>
                    <label class="text-sm">Distância segura entre peças, mm
                        <input class="mt-1 block w-full rounded border-gray-300" type="number" name="distancia_pecas_mm" min="{{ $chapa->espessura->espacamento_minimo_mm }}" max="100" step="0.001" value="{{ $preview['distancia_pecas_mm'] ?? $chapa->espessura->espacamento_minimo_mm }}" required>
                    </label>
                    <label class="text-sm">Margem externa da chapa, mm
                        <input class="mt-1 block w-full rounded border-gray-300" type="number" name="margem_borda_mm" min="{{ $chapa->espessura->espacamento_minimo_mm }}" max="100" step="0.001" value="{{ $preview['margem_borda_mm'] ?? $chapa->espessura->espacamento_minimo_mm }}" required>
                    </label>
                    <label class="text-sm">Entrada do corte (lead-in), mm
                        <input class="mt-1 block w-full rounded border-gray-300" type="number" name="lead_in_mm" min="0.001" max="100" step="0.001" value="{{ $preview['lead_in_mm'] ?? $chapa->espessura->lead_in_mm }}" required>
                    </label>
                </div>
                <p class="text-xs text-gray-500">Distância e margem mínimas para esta espessura: {{ $chapa->espessura->espacamento_minimo_mm }} mm. As distâncias são medidas entre as linhas de corte.</p>
                <p class="text-xs text-gray-500">Alterar estes valores reorganiza o lote e substitui posições manuais nesta prévia.</p>
                <button class="rounded border border-indigo-700 px-4 py-2 text-indigo-700">Organizar automaticamente</button>
            </form>
            <div class="flex flex-col justify-end gap-3">
                @if($posicionadas === $total)
                    <form id="exportar-form" method="POST" action="{{ route('arranjos.aceitar', $token) }}">@csrf<button id="exportar-arranjo" type="button" class="w-full rounded bg-emerald-700 px-4 py-2 text-white">{{ ($preview['pedido_id'] ?? null) ? 'Confirmar revisão e gerar CNC' : 'Exportar CNC e criar pedido' }}</button></form>
                @else
                    <button class="w-full cursor-not-allowed rounded bg-gray-400 px-4 py-2 text-white" disabled title="É preciso distribuir todas as peças antes de exportar">{{ ($preview['pedido_id'] ?? null) ? 'Confirmar revisão e gerar CNC' : 'Exportar CNC e criar pedido' }}</button>
                @endif
            </div>
        </div>
    </div>
    <div id="preview-config" hidden data-url="{{ route('arranjos.data', ['token' => $token, 'plano' => $indice]) }}" data-salvar="{{ route('arranjos.posicoes', $token) }}" data-plano="{{ $indice }}"></div>
    @vite('resources/js/editor.js')
</x-app-layout>
