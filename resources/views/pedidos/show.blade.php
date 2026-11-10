<x-app-layout>
    <x-slot name="header"><x-page-heading :back-href="route('pedidos.index')">Pedido #{{ $pedido->id }}{{ $pedido->nome ? ' · '.$pedido->nome : '' }}</x-page-heading></x-slot>
    <div class="mx-auto max-w-5xl space-y-5 p-6" x-data="pedidoStatus(@js(route('pedidos.status', $pedido)), @js($pedido->status->value))" x-init="start()">
        <x-notice />
        <nav class="flex flex-wrap gap-2 rounded bg-white p-2 shadow" role="tablist" aria-label="Seções do pedido" @keydown.arrow-right.prevent="moverAba(1)" @keydown.arrow-left.prevent="moverAba(-1)">
            <button type="button" id="aba-pedido" role="tab" aria-controls="painel-pedido" :aria-selected="aba === 'pedido'" :tabindex="aba === 'pedido' ? 0 : -1" @click="selecionarAba('pedido')" :class="aba === 'pedido' ? 'bg-indigo-700 text-white' : 'text-indigo-700'" class="rounded px-5 py-2 text-sm font-semibold">Pedido</button>
            <button type="button" id="aba-gestao" role="tab" aria-controls="painel-gestao" :aria-selected="aba === 'gestao'" :tabindex="aba === 'gestao' ? 0 : -1" @click="selecionarAba('gestao')" :class="aba === 'gestao' ? 'bg-indigo-700 text-white' : 'text-indigo-700'" class="rounded px-5 py-2 text-sm font-semibold">Gestão</button>
            <button type="button" id="aba-custos" role="tab" aria-controls="painel-custos" :aria-selected="aba === 'custos'" :tabindex="aba === 'custos' ? 0 : -1" @click="selecionarAba('custos')" :class="aba === 'custos' ? 'bg-indigo-700 text-white' : 'text-indigo-700'" class="rounded px-5 py-2 text-sm font-semibold">Custos</button>
        </nav>
        <section id="painel-pedido" role="tabpanel" aria-labelledby="aba-pedido" x-show="aba === 'pedido'" class="space-y-5">
        <div class="rounded bg-white p-5 shadow">
            <p><strong>Status:</strong> <span x-text="status">{{ $pedido->status->value }}</span></p>
            @if($pedido->chapa)<p><strong>Chapas do pedido:</strong> {{ 1 + $pedido->chapasInternas->count() }}</p><p><strong>Chapa 1:</strong> #{{ $pedido->chapa->id }} — {{ $pedido->chapa->material->nome }} {{ $pedido->chapa->espessura->valor_mm }} mm — {{ $pedido->chapa->largura_mm }} × {{ $pedido->chapa->altura_mm }} mm</p><p>{{ count($pedido->resultado_nesting_json['pecas_posicionadas'] ?? []) }} peças · folga mínima {{ number_format((float) ($pedido->resultado_nesting_json['distancia_pecas_mm'] ?? 0), 3, ',', '.') }} mm</p>@endif
            @if($pedido->exportado_em)<p><strong>Exportado:</strong> {{ $pedido->exportado_em->format('d/m/Y H:i') }} · estoque baixado</p>@endif
            @if($pedido->retalho && $pedido->retalho->quantidade_disponivel > 0)<p><strong>Retalho criado:</strong> <a class="text-indigo-700 underline" href="{{ route('chapas.index') }}">Chapa #{{ $pedido->retalho->id }}</a> — {{ $pedido->retalho->largura_mm }} × {{ $pedido->retalho->altura_mm }} mm</p>@endif
            @include('pedidos.partials.composicao', ['composicao' => $composicoes[1] ?? null])
            @if($pedido->erro_mensagem)<p class="mt-2 text-red-700">{{ $pedido->erro_mensagem }}</p>@endif
            <h2 class="mt-4 font-semibold">Arquivos para download</h2>
            <div class="mt-2 flex flex-wrap gap-3">
            @if($pedido->cnc_saida)<a class="inline-block rounded bg-emerald-700 px-4 py-2 text-white" href="{{ route('pedidos.cnc', $pedido) }}">Baixar CNC{{ $pedido->chapasInternas->isNotEmpty() ? ' · Chapa 1' : ' para a máquina' }}</a>@endif
            @if($pedido->dxf_saida)<a class="inline-block rounded border border-emerald-700 px-4 py-2 text-emerald-700" href="{{ route('pedidos.dxf', $pedido) }}">Baixar DXF técnico</a>@endif
            @if(!empty($pedido->resultado_nesting_json['pecas_posicionadas']))<a class="inline-block rounded border border-indigo-700 px-4 py-2 text-indigo-700" href="{{ route('pedidos.pdf', $pedido) }}">Baixar PDF{{ $pedido->chapasInternas->isNotEmpty() ? ' · Chapa 1' : ' do arranjo' }}</a>@endif
            @if(!$pedido->cnc_saida && !$pedido->dxf_saida && empty($pedido->resultado_nesting_json['pecas_posicionadas']))<p class="text-sm text-gray-600">Os arquivos estarão disponíveis após a otimização.</p>@endif
            </div>
            @if($pedido->exportado_em && !$pedido->cnc_saida)<p class="mt-2 text-sm text-gray-600">CNC em preparação. Atualize esta página em instantes.</p>@endif
            <p x-show="erro" x-text="erro" class="mt-2 text-red-700"></p>
        </div>
        @foreach($pedido->chapasInternas as $plano)
            <div class="rounded bg-white p-5 shadow">
                <h2 class="font-semibold">Chapa {{ $plano->numero }} do mesmo pedido</h2>
                <p class="mt-2">#{{ $plano->chapa->id }} — {{ $plano->chapa->material->nome }} {{ $plano->chapa->espessura->valor_mm }} mm — {{ $plano->chapa->largura_mm }} × {{ $plano->chapa->altura_mm }} mm</p>
                <p>{{ count($plano->resultado_nesting_json['pecas_posicionadas'] ?? []) }} peças · folga mínima {{ number_format((float) ($plano->resultado_nesting_json['distancia_pecas_mm'] ?? 0), 3, ',', '.') }} mm · {{ $plano->resultado_nesting_json['area_utilizada_pct'] ?? '—' }}% em contornos · {{ $plano->tempo_corte_estimado_seg }} s estimados</p>
                @include('pedidos.partials.composicao', ['composicao' => $composicoes[$plano->numero] ?? null])
                @if($plano->retalho && $plano->retalho->quantidade_disponivel > 0)<p>Retalho: #{{ $plano->retalho->id }} — {{ $plano->retalho->largura_mm }} × {{ $plano->retalho->altura_mm }} mm</p>@endif
                @if($plano->erro_mensagem)<p class="text-red-700">{{ $plano->erro_mensagem }}</p>@endif
                <div class="mt-3 flex flex-wrap gap-3">
                    @if($pedido->status === \App\Enums\StatusPedido::Pronto)<a class="rounded border border-indigo-700 px-4 py-2 text-indigo-700" href="{{ route('pedidos.editor', [$pedido, 'chapa' => $plano->numero]) }}">Editar chapa {{ $plano->numero }}</a>@endif
                    @if($plano->cnc_saida)<a class="rounded bg-emerald-700 px-4 py-2 text-white" href="{{ route('pedidos.chapas.cnc', [$pedido, $plano->numero]) }}">Baixar CNC · Chapa {{ $plano->numero }}</a>@endif
                    @if($plano->dxf_saida)<a class="rounded border border-emerald-700 px-4 py-2 text-emerald-700" href="{{ route('pedidos.chapas.dxf', [$pedido, $plano->numero]) }}">Baixar DXF técnico</a>@endif
                    <a class="rounded border border-indigo-700 px-4 py-2 text-indigo-700" href="{{ route('pedidos.chapas.pdf', [$pedido, $plano->numero]) }}">Baixar PDF · Chapa {{ $plano->numero }}</a>
                </div>
            </div>
        @endforeach
        <div class="rounded bg-white p-5 shadow"><h2 class="font-semibold">Peças do pedido</h2><ul class="mt-2 list-disc pl-5">@foreach($pedido->pecas as $peca)<li>{{ $peca->nome }} × {{ $peca->pivot->quantidade }}</li>@endforeach</ul></div>
        @if($pedido->status === \App\Enums\StatusPedido::Pronto)
            @if($pedido->estoque_baixado_em)
                <a class="inline-block rounded bg-indigo-700 px-4 py-2 text-white" href="{{ route('pedidos.acrescentar', $pedido) }}">Editar arranjo e reotimizar</a>
                <a class="ml-2 inline-block rounded border border-indigo-700 px-4 py-2 text-indigo-700" href="{{ route('pedidos.editor', $pedido) }}">Ajustar posições na chapa</a>
            @else
                <a class="inline-block rounded bg-indigo-700 px-4 py-2 text-white" href="{{ route('pedidos.editor', $pedido) }}">Abrir editor visual</a>
            @endif
            <div class="rounded bg-white p-5 shadow">
                <h2 class="font-semibold">Resultado do nesting</h2>
                <p>Área dentro dos contornos das peças: {{ $pedido->resultado_nesting_json['area_utilizada_pct'] ?? '—' }}%</p>
                <p>Tempo estimado: {{ $pedido->tempo_corte_estimado_seg }} s</p>
                <table class="mt-3 w-full text-left"><thead><tr><th>Peça</th><th>Instância</th><th>X (mm)</th><th>Y (mm)</th><th>Rotação</th></tr></thead><tbody>
                    @foreach($pedido->resultado_nesting_json['pecas_posicionadas'] ?? [] as $item)
                        <tr class="border-t"><td class="py-2">{{ $item['id'] }}</td><td>{{ $item['instancia'] }}</td><td>{{ $item['x'] }}</td><td>{{ $item['y'] }}</td><td>{{ $item['rotacao_graus'] }}°</td></tr>
                    @endforeach
                </tbody></table>
            </div>
        @endif
        </section>
        <section id="painel-gestao" role="tabpanel" aria-labelledby="aba-gestao" x-show="aba === 'gestao'" x-cloak class="space-y-5">
            <div class="rounded bg-white p-5 shadow">
                <h2 class="text-lg font-semibold">Controle do pedido</h2>
                <p class="mt-2">Status: <strong x-text="status">{{ $pedido->status->value }}</strong></p>
                <p>Previsão: {{ $pedido->data_prevista?->format('d/m/Y') ?? '—' }} · Entrega: {{ $pedido->data_entrega?->format('d/m/Y') ?? '—' }}</p>
                <p>Tempo estimado: {{ $pedido->tempo_corte_estimado_seg ?? '—' }} s · Real: {{ $pedido->tempo_corte_real_seg ?? '—' }} s</p>
                <form method="POST" action="{{ route('pedidos.gestao', $pedido) }}" class="mt-4 grid gap-3 border-t pt-4 sm:grid-cols-2">@csrf @method('PATCH')
                    <label class="block text-sm sm:col-span-2">Nome do arranjo<input class="mt-1 block w-full rounded border-gray-300" type="text" name="nome" maxlength="120" value="{{ old('nome', $pedido->nome) }}"></label>
                    <label class="block text-sm">Data prevista<input class="mt-1 block w-full rounded border-gray-300" type="date" name="data_prevista" value="{{ old('data_prevista', $pedido->data_prevista?->format('Y-m-d')) }}"></label>
                    <label class="block text-sm">Data de entrega<input class="mt-1 block w-full rounded border-gray-300" type="date" name="data_entrega" value="{{ old('data_entrega', $pedido->data_entrega?->format('Y-m-d')) }}"></label>
                    <label class="block text-sm sm:col-span-2">Tempo real (segundos)<input class="mt-1 block w-full rounded border-gray-300" type="number" min="1" name="tempo_corte_real_seg" value="{{ old('tempo_corte_real_seg', $pedido->tempo_corte_real_seg) }}"></label>
                    @if(!in_array($pedido->status, [\App\Enums\StatusPedido::EmProducao, \App\Enums\StatusPedido::Concluido], true))
                        <label class="flex items-center gap-2 text-sm sm:col-span-2"><input type="hidden" name="usar_tabs" value="0"><input type="checkbox" name="usar_tabs" value="1" @checked(old('usar_tabs', $pedido->usar_tabs))> Usar microjunções no contorno externo</label>
                        <label class="block text-sm sm:col-span-2">Tamanho da tab (mm)<input class="mt-1 block w-full rounded border-gray-300" type="number" min="0.001" max="100" step="0.001" name="tamanho_tab_mm" value="{{ old('tamanho_tab_mm', $pedido->tamanho_tab_mm ?? 1) }}"></label>
                    @else
                        <p class="text-sm sm:col-span-2">Microjunções: {{ $pedido->usar_tabs ? 'ativadas · '.$pedido->tamanho_tab_mm.' mm' : 'desativadas' }}</p>
                    @endif
                    <button class="rounded border border-indigo-700 px-4 py-2 text-indigo-700 sm:justify-self-start">Salvar gestão</button>
                </form>
            </div>
            <div class="rounded bg-white p-5 shadow">
                <h2 class="font-semibold">Produção</h2>
                @if($pedido->status === \App\Enums\StatusPedido::Pronto && $pedido->estoque_baixado_em)
                    <form method="POST" action="{{ route('pedidos.iniciar', $pedido) }}" class="mt-3">@csrf<button class="rounded bg-indigo-700 px-4 py-2 text-white">Iniciar produção</button></form>
                @endif
                @if($pedido->status === \App\Enums\StatusPedido::EmProducao)
                    <form method="POST" action="{{ route('pedidos.concluir', $pedido) }}" class="mt-3 space-y-3">@csrf
                        <label class="block">Tempo real de corte (segundos)<input class="mt-1 block rounded border-gray-300" name="tempo_corte_real_seg" type="number" min="1" value="{{ old('tempo_corte_real_seg') }}"></label>
                        <button class="rounded bg-indigo-700 px-4 py-2 text-white">Concluir produção</button>
                    </form>
                @endif
                @if(in_array($pedido->status, [\App\Enums\StatusPedido::Rascunho, \App\Enums\StatusPedido::Erro], true))
                    <form method="POST" action="{{ route('pedidos.confirmar', $pedido) }}" class="mt-3">@csrf<button class="rounded bg-indigo-700 px-4 py-2 text-white">Confirmar e rodar nesting</button></form>
                @endif
                @if($pedido->status === \App\Enums\StatusPedido::Concluido)<p class="mt-3 text-sm text-gray-600">Corte concluído em {{ $pedido->concluido_em?->format('d/m/Y H:i') }}.</p>@endif
            </div>
        @if(!in_array($pedido->status, [\App\Enums\StatusPedido::AguardandoNesting, \App\Enums\StatusPedido::EmProducao, \App\Enums\StatusPedido::Concluido], true))
            <form method="POST" action="{{ route('pedidos.destroy', $pedido) }}" onsubmit="return confirm('Excluir o Pedido #{{ $pedido->id }}? O estoque será revertido se o retalho ainda não tiver sido usado.');">
                @csrf @method('DELETE')
                <button class="rounded border border-red-700 px-4 py-2 text-red-700">Excluir pedido</button>
            </form>
        @endif
        </section>
        <section id="painel-custos" role="tabpanel" aria-labelledby="aba-custos" x-show="aba === 'custos'" x-cloak>
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded bg-white p-5 shadow"><h2 class="text-sm text-gray-500">Material</h2><p class="mt-2 text-2xl font-bold">R$ {{ number_format((float) $pedido->custo_material, 2, ',', '.') }}</p></div>
                <div class="rounded bg-white p-5 shadow"><h2 class="text-sm text-gray-500">Máquina</h2><p class="mt-2 text-2xl font-bold">R$ {{ number_format((float) $pedido->custo_maquina, 2, ',', '.') }}</p></div>
                <div class="rounded bg-white p-5 shadow"><h2 class="text-sm text-gray-500">Custo total</h2><p class="mt-2 text-2xl font-bold">R$ {{ number_format((float) $pedido->custo_total, 2, ',', '.') }}</p></div>
            </div>
            <p class="mt-4 rounded bg-white p-4 text-sm text-gray-600 shadow">O custo do material desconta o valor proporcional do retalho aproveitável. O custo da máquina usa a tarifa por hora da espessura e o tempo real, quando informado.</p>
        </section>
    </div>
    <script>
        function pedidoStatus(url, initialStatus) {
            return {
                status: initialStatus, erro: null,
                aba: sessionStorage.getItem('pedido-{{ $pedido->id }}-aba') || 'pedido',
                selecionarAba(aba) {
                    this.aba = aba;
                    sessionStorage.setItem('pedido-{{ $pedido->id }}-aba', aba);
                },
                moverAba(direcao) {
                    const abas = ['pedido', 'gestao', 'custos'];
                    this.selecionarAba(abas[(abas.indexOf(this.aba) + direcao + abas.length) % abas.length]);
                    requestAnimationFrame(() => document.getElementById('aba-' + this.aba).focus());
                },
                async refresh() {
                    try {
                        const response = await fetch(url, {headers: {'Accept': 'application/json'}});
                        if (!response.ok) return;
                        const data = await response.json();
                        if (data.status !== this.status && ['pronto', 'erro'].includes(data.status)) window.location.reload();
                        this.status = data.status;
                        this.erro = data.erro;
                    } catch (error) { /* The next poll retries automatically. */ }
                },
                start() {
                    if (this.status !== 'aguardando_nesting') return;
                    const interval = setInterval(() => {
                        this.refresh();
                        if (this.status !== 'aguardando_nesting') clearInterval(interval);
                    }, 3000);
                    window.addEventListener('beforeunload', () => clearInterval(interval), {once: true});
                },
            };
        }
    </script>
</x-app-layout>
