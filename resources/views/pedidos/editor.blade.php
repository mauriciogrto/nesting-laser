<x-app-layout>
    <x-slot name="header"><x-page-heading :back-href="route('pedidos.show', $pedido)">Editor do pedido #{{ $pedido->id }} · Chapa {{ $numero ?? 1 }}</x-page-heading></x-slot>
    <div class="mx-auto max-w-7xl space-y-4 p-6" x-data="{ tempo: {{ $pedido->tempo_corte_estimado_seg ?? 0 }}, colisao: false, mensagem: '' }" @tempo-atualizado.window="tempo = $event.detail" @colisao-atualizada.window="colisao = $event.detail" @editor-mensagem.window="mensagem = $event.detail">
        <p class="text-sm text-gray-600">Arraste as peças ou use a alça de rotação. O trajeto vermelho mostra o corte do CNC; as linhas amarelas mostram entrada e saída. Clique numa peça e use + para ampliar perto dela; a roda do mouse também amplia. Use Alt + arrastar para mover a vista ampliada. Na vista da chapa inteira, a folga em milímetros pode ser menor que um pixel. Peças sobrepostas ficam destacadas em vermelho.</p>
        <p x-show="mensagem" x-text="mensagem" class="rounded bg-yellow-50 p-3 text-yellow-900" role="status"></p>
        <p x-show="colisao" class="rounded bg-red-50 p-3 text-red-800">Há peças sobrepostas. Ajuste as posições antes de salvar.</p>
        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_16rem]">
            <div id="nesting-canvas" class="relative h-[65vh] min-h-[420px] w-full overflow-hidden rounded border bg-white shadow" aria-label="Arranjo visual da chapa"></div>
            <div class="space-y-4 rounded bg-white p-4 shadow">
                <h2 class="font-semibold">Ordem de corte</h2>
                <ol id="cut-order" class="space-y-2 text-sm"></ol>
                <div class="flex gap-2">
                    <button id="desfazer-arranjo" type="button" class="app-undo-button rounded border px-3 py-1 text-sm disabled:opacity-40" disabled>↶ Desfazer</button>
                    <button id="refazer-arranjo" type="button" class="app-undo-button rounded border px-3 py-1 text-sm disabled:opacity-40" disabled>↷ Refazer</button>
                </div>
                <p>Tempo estimado: <strong x-text="`${tempo} s`"></strong></p>
                <p>Área dos contornos das peças: <strong><span id="area-chapa">{{ number_format((float) ($pedido->resultado_nesting_json['area_utilizada_pct'] ?? 0), 2, ',', '.') }}</span>%</strong></p>
                <button id="recalcular-tempo" class="w-full rounded border border-indigo-700 px-3 py-2 text-indigo-700">Recalcular tempo de corte</button>
                <button id="organizar-pedido" type="button" class="w-full rounded border border-emerald-700 px-3 py-2 text-emerald-800">Organizar automaticamente</button>
                <button id="confirmar-arranjo" class="w-full rounded bg-indigo-700 px-3 py-2 text-white">{{ $pedido->estoque_baixado_em ? 'Salvar alterações e atualizar CNC' : 'Confirmar arranjo final' }}</button>
            </div>
        </div>
    </div>
    <div id="editor-config" hidden
         data-url="{{ route('pedidos.editor.data', [$pedido, 'chapa' => $numero ?? 1]) }}"
         data-recalcular="{{ route('pedidos.editor.recalcular', [$pedido, 'chapa' => $numero ?? 1]) }}"
         data-organizar="{{ route('pedidos.editor.organizar', [$pedido, 'chapa' => $numero ?? 1]) }}"
         data-confirmar="{{ route('pedidos.editor.confirmar', [$pedido, 'chapa' => $numero ?? 1]) }}"
         data-pedido="{{ route('pedidos.show', $pedido) }}"></div>
    @vite('resources/js/editor.js')
</x-app-layout>
