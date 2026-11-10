<x-app-layout>
    <x-slot name="header"><x-page-heading :back-href="$revisao ? route('pedidos.show', $revisao) : route('pedidos.index')">{{ $revisao ? 'Editar arranjo · Pedido #'.$revisao->id : 'Otimizar · novo arranjo' }}</x-page-heading></x-slot>
    <div class="mx-auto max-w-4xl space-y-5 p-6">
        <x-notice />
        <p class="text-sm text-gray-600">{{ $revisao ? 'Altere quantidades, remova peças ou adicione desenhos. Confira a nova distribuição antes de confirmar: o pedido e os programas CNC só serão atualizados depois da confirmação.' : 'Envie DXFs ou escolha peças já cadastradas. Informe a quantidade de cada desenho, até '.$maxPecas.' unidades no total. O arranjo fica disponível por 15 minutos antes da exportação.' }}</p>
        <form method="POST" action="{{ route('arranjos.store') }}" enctype="multipart/form-data" class="space-y-5 rounded bg-white p-6 shadow" x-data="{ enviando: false }" @submit="enviando = true">
            @csrf
            @if($revisao)<input type="hidden" name="pedido_id" value="{{ $revisao->id }}"><input type="hidden" name="substituir_pecas" value="1">@endif
            @if($revisao)
                <section class="rounded border border-gray-200 p-4 sm:p-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div><h2 class="text-lg font-semibold">Peças do pedido</h2><p class="text-sm text-gray-500">Edite a quantidade ou remova qualquer item antes de reotimizar.</p></div>
                        <button id="abrir-adicionar-peca" type="button" class="rounded bg-indigo-700 px-4 py-2 text-sm font-medium text-white">+ Adicionar nova peça</button>
                    </div>
                    <div id="pecas-adicionadas" class="mt-4 space-y-2" aria-live="polite"></div>
                    <p id="preview-total" class="mt-3 text-sm text-gray-600" aria-live="polite"></p>
                </section>
            @endif
            @unless($revisao)
            <label class="block">Nome do arranjo
                <input class="mt-1 block w-full rounded border-gray-300" type="text" name="nome" maxlength="120" value="{{ old('nome') }}" placeholder="Ex.: teste">
            </label>
            @endunless
            @if($revisao)<dialog id="modal-adicionar-peca" class="w-[min(94vw,44rem)] max-h-[85vh] overflow-y-auto rounded-xl border-0 bg-gray-50 p-0 text-gray-900 shadow-2xl backdrop:bg-slate-900/55" aria-labelledby="titulo-adicionar-peca">
                <div class="sticky top-0 z-10 flex items-center justify-between border-b bg-white px-5 py-4">
                    <h2 id="titulo-adicionar-peca" class="text-lg font-semibold">Adicionar nova peça</h2>
                    <button id="fechar-adicionar-peca" type="button" class="rounded px-3 py-1 text-gray-600 hover:bg-gray-100" aria-label="Fechar janela">Fechar</button>
                </div>
                <div class="space-y-5 p-5">
            @endif
            <section class="space-y-3">
                <h2 class="font-semibold">Enviar desenhos novos</h2>
                <label class="block">Arquivos DXF (opcional)
                    <input id="preview-arquivos" class="mt-1 block w-full rounded border-gray-300" type="file" name="arquivos[]" accept=".dxf" multiple>
                    <span class="text-xs text-gray-500">Até {{ $maxArquivos }} arquivos, 5 MB cada. Os contornos de corte precisam estar fechados no modelspace do DXF.</span>
                </label>
            </section>
            <div id="preview-quantidades" class="space-y-2" aria-live="polite"></div>
            <section class="space-y-3 border-t pt-4">
                <h2 class="font-semibold">Peças cadastradas</h2>
                <label class="block" for="buscar-peca">Buscar por nome ou código</label>
                <div class="flex gap-2">
                    <input id="buscar-peca" class="min-w-0 flex-1 rounded border-gray-300" type="search" autocomplete="off" placeholder="Digite o nome ou código da peça">
                    <button id="buscar-pecas" type="button" class="rounded border border-indigo-700 px-4 py-2 text-indigo-700">Buscar</button>
                </div>
                <div id="resultado-busca-pecas" class="space-y-2" aria-live="polite"></div>
                @unless($revisao)<div class="border-t pt-3">
                    <h3 class="font-medium">Peças adicionadas ao arranjo</h3>
                    <div id="pecas-adicionadas" class="mt-2 space-y-2" aria-live="polite"></div>
                </div>@endunless
            </section>
            @if($revisao)</div></dialog>@else<p id="preview-total" class="text-sm text-gray-600" aria-live="polite"></p>@endif
            @if($revisao)<h2 class="border-t pt-4 text-lg font-semibold">Material e parâmetros de corte</h2>@endif
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block">Material
                    <select id="preview-material" class="mt-1 block w-full rounded border-gray-300" @if($revisao) disabled @else name="material_id" required @endif>
                        <option value="">Selecione</option>
                        @foreach($materiais as $material)<option value="{{ $material->id }}" @selected(old('material_id', $revisao?->chapa->material_id) == $material->id)>{{ $material->nome }}</option>@endforeach
                    </select>
                    @if($revisao)<input type="hidden" name="material_id" value="{{ $revisao->chapa->material_id }}">@endif
                </label>
                <label class="block">Espessura
                    <select id="preview-espessura" class="mt-1 block w-full rounded border-gray-300" @if($revisao) disabled @else name="espessura_id" required @endif>
                        <option value="">Selecione</option>
                        @foreach($espessuras as $espessura)<option value="{{ $espessura->id }}" data-material="{{ $espessura->material_id }}" data-kerf="{{ $espessura->kerf_mm }}" data-espacamento="{{ $espessura->espacamento_minimo_mm }}" data-lead-in="{{ $espessura->lead_in_mm }}" @selected(old('espessura_id', $revisao?->chapa->espessura_id) == $espessura->id)>{{ $espessura->valor_mm }} mm</option>@endforeach
                    </select>
                    @if($revisao)<input type="hidden" name="espessura_id" value="{{ $revisao->chapa->espessura_id }}">@endif
                </label>
            </div>
            @unless($revisao)<label class="block">Chapa <span class="text-gray-500">(opcional)</span>
                <select id="preview-chapa" class="mt-1 block w-full rounded border-gray-300" name="chapa_id">
                    <option value="">Sugerir automaticamente: retalhos primeiro</option>
                    @foreach($chapas as $chapa)
                        <option value="{{ $chapa->id }}" data-material="{{ $chapa->material_id }}" data-espessura="{{ $chapa->espessura_id }}" @selected(old('chapa_id') == $chapa->id)>
                            #{{ $chapa->id }} · {{ ucfirst($chapa->origem->value) }} · {{ $chapa->largura_mm }} × {{ $chapa->altura_mm }} mm · disponível: {{ $chapa->quantidade_disponivel }}
                        </option>
                    @endforeach
                </select>
            </label>@else<input id="preview-chapa" type="hidden" value="">@endunless
            <section class="grid gap-4 border-t pt-4 sm:grid-cols-2" aria-label="Parâmetros de corte e segurança">
                <label class="block">Largura do corte (kerf), mm
                    <input id="preview-kerf" class="mt-1 block w-full rounded border-gray-300" type="number" name="kerf_mm" min="0" max="20" step="0.001" value="{{ old('kerf_mm', $revisao?->resultado_nesting_json['kerf_mm'] ?? null) }}" placeholder="Padrão da espessura">
                </label>
                <label class="block">Distância segura entre peças, mm
                    <input id="preview-distancia" class="mt-1 block w-full rounded border-gray-300" type="number" name="distancia_pecas_mm" min="0" max="100" step="0.001" value="{{ old('distancia_pecas_mm', $revisao?->resultado_nesting_json['distancia_pecas_mm'] ?? null) }}" required>
                </label>
                <label class="block">Margem externa da chapa, mm
                    <input id="preview-margem" class="mt-1 block w-full rounded border-gray-300" type="number" name="margem_borda_mm" min="0" max="100" step="0.001" value="{{ old('margem_borda_mm', $revisao?->resultado_nesting_json['margem_borda_mm'] ?? null) }}" required>
                </label>
                <label class="block">Entrada do corte (lead-in), mm
                    <input id="preview-lead-in" class="mt-1 block w-full rounded border-gray-300" type="number" name="lead_in_mm" min="0.001" max="100" step="0.001" value="{{ old('lead_in_mm', $revisao?->resultado_nesting_json['lead_in_mm'] ?? null) }}" required>
                </label>
                <p class="text-xs text-gray-500 sm:col-span-2">Distância e margem não podem ser menores que o mínimo de segurança da espessura selecionada. Kerf é a largura removida pelo corte.</p>
            </section>
            <button class="rounded bg-indigo-700 px-5 py-2 text-white disabled:opacity-60" :disabled="enviando"><span x-show="!enviando">{{ $revisao ? 'Pré-visualizar novo arranjo' : 'Criar arranjo' }}</span><span x-show="enviando">Processando DXFs e calculando arranjo...</span></button>
        </form>
    </div>
    <script>
        const material = document.getElementById('preview-material');
        const espessura = document.getElementById('preview-espessura');
        const chapa = document.getElementById('preview-chapa');
        const kerf = document.getElementById('preview-kerf');
        const distancia = document.getElementById('preview-distancia');
        const margem = document.getElementById('preview-margem');
        const leadIn = document.getElementById('preview-lead-in');
        const arquivos = document.getElementById('preview-arquivos');
        const quantidades = document.getElementById('preview-quantidades');
        const total = document.getElementById('preview-total');
        const campoBusca = document.getElementById('buscar-peca');
        const resultadoBusca = document.getElementById('resultado-busca-pecas');
        const adicionadas = document.getElementById('pecas-adicionadas');
        const pecasSelecionadas = new Map(@js($pecasSelecionadas).map(peca => [Number(peca.id), peca]));
        const quantidadeExistente = 0;
        let perfilAnterior = null;
        const modalAdicionar = document.getElementById('modal-adicionar-peca');
        document.getElementById('abrir-adicionar-peca')?.addEventListener('click', () => modalAdicionar.showModal());
        document.getElementById('fechar-adicionar-peca')?.addEventListener('click', () => modalAdicionar.close());
        let resultadosAtuais = [];
        function descricaoPeca(peca) {
            return `#${peca.id} · ${peca.largura_bbox_mm} × ${peca.altura_bbox_mm} mm`;
        }
        function renderizarSelecionadas() {
            adicionadas.replaceChildren();
            if (!pecasSelecionadas.size) {
                const vazio = document.createElement('p');
                vazio.className = 'text-sm text-gray-500';
                vazio.textContent = @js($revisao ? 'Nenhuma peça no arranjo. Adicione uma peça para continuar.' : 'Nenhuma peça adicionada.');
                adicionadas.append(vazio);
                return;
            }
            for (const peca of pecasSelecionadas.values()) {
                const linha = document.createElement('div');
                linha.className = 'flex flex-wrap items-center justify-between gap-3 rounded border p-3';
                const identificacao = document.createElement('div');
                const nome = document.createElement('strong');
                nome.className = 'block';
                nome.textContent = peca.nome;
                const dimensoes = document.createElement('span');
                dimensoes.className = 'text-xs text-gray-500';
                dimensoes.textContent = descricaoPeca(peca);
                identificacao.append(nome, dimensoes);
                const controles = document.createElement('div');
                controles.className = 'flex items-center gap-2';
                const quantidade = document.createElement('input');
                quantidade.className = 'quantidade-catalogo w-24 rounded border-gray-300';
                quantidade.type = 'number';
                quantidade.name = `pecas_existentes[${peca.id}]`;
                quantidade.min = '1';
                quantidade.max = '{{ $maxPecas }}';
                quantidade.required = true;
                quantidade.value = peca.quantidade;
                quantidade.setAttribute('aria-label', `Quantidade de ${peca.nome}`);
                quantidade.addEventListener('input', () => { peca.quantidade = Number(quantidade.value || 0); atualizarTotal(); });
                const remover = document.createElement('button');
                remover.type = 'button';
                remover.className = 'text-sm text-red-700 underline';
                remover.textContent = 'Remover';
                remover.setAttribute('aria-label', `Remover ${peca.nome}`);
                remover.addEventListener('click', () => {
                    pecasSelecionadas.delete(Number(peca.id));
                    renderizarSelecionadas();
                    renderizarResultados();
                    atualizarTotal();
                });
                controles.append(quantidade, remover);
                linha.append(identificacao, controles);
                adicionadas.append(linha);
            }
        }
        function renderizarResultados() {
            resultadoBusca.replaceChildren();
            if (!resultadosAtuais.length) {
                const vazio = document.createElement('p');
                vazio.className = 'text-sm text-gray-500';
                vazio.textContent = 'Nenhuma peça encontrada.';
                resultadoBusca.append(vazio);
                return;
            }
            for (const peca of resultadosAtuais) {
                const linha = document.createElement('div');
                linha.className = 'flex items-center justify-between gap-3 rounded border bg-gray-50 p-3';
                const identificacao = document.createElement('span');
                identificacao.className = 'min-w-0 text-sm';
                identificacao.textContent = `${peca.nome} · ${descricaoPeca(peca)}`;
                const adicionar = document.createElement('button');
                adicionar.type = 'button';
                adicionar.className = 'shrink-0 rounded border border-indigo-700 px-3 py-1 text-sm text-indigo-700 disabled:opacity-50';
                adicionar.disabled = pecasSelecionadas.has(Number(peca.id));
                adicionar.textContent = adicionar.disabled ? 'Adicionada' : 'Adicionar';
                adicionar.setAttribute('aria-label', `Adicionar ${peca.nome}`);
                adicionar.addEventListener('click', () => {
                    pecasSelecionadas.set(Number(peca.id), {...peca, quantidade: 1});
                    renderizarSelecionadas();
                    renderizarResultados();
                    atualizarTotal();
                });
                linha.append(identificacao, adicionar);
                resultadoBusca.append(linha);
            }
        }
        async function buscarPecas() {
            const q = campoBusca.value.trim();
            if (!q) {
                resultadoBusca.textContent = 'Digite o nome ou código da peça.';
                return;
            }
            resultadoBusca.textContent = 'Buscando peças...';
            try {
                const resposta = await fetch(@js(route('arranjos.pecas.buscar')) + '?q=' + encodeURIComponent(q), {
                    headers: {'Accept': 'application/json'},
                });
                if (!resposta.ok) throw new Error('Busca indisponível. Tente novamente.');
                const dados = await resposta.json();
                resultadosAtuais = dados.pecas;
                renderizarResultados();
            } catch (erro) {
                resultadoBusca.textContent = erro.message;
            }
        }
        document.getElementById('buscar-pecas').addEventListener('click', buscarPecas);
        campoBusca.addEventListener('keydown', evento => {
            if (evento.key === 'Enter') { evento.preventDefault(); buscarPecas(); }
        });
        renderizarSelecionadas();
        function atualizarTotal() {
            const pecas = quantidadeExistente + [...document.querySelectorAll('#preview-quantidades input, .quantidade-catalogo')].reduce((sum, input) => sum + Number(input.value || 0), 0);
            total.textContent = pecas ? `${pecas} peça(s) solicitada(s) · máximo {{ $maxPecas }}` : '';
            total.classList.toggle('text-red-700', pecas > {{ $maxPecas }});
        }
        arquivos.addEventListener('change', () => {
            arquivos.setCustomValidity(arquivos.files.length > {{ $maxArquivos }} ? 'Selecione no máximo {{ $maxArquivos }} desenhos DXF.' : '');
            arquivos.reportValidity();
            quantidades.replaceChildren();
            [...arquivos.files].forEach((arquivo, index) => {
                const label = document.createElement('label');
                label.className = 'flex items-center justify-between gap-4 rounded border p-3';
                const nome = document.createElement('span');
                nome.className = 'min-w-0 truncate';
                nome.textContent = arquivo.name;
                const quantidade = document.createElement('input');
                quantidade.type = 'number';
                quantidade.name = `quantidades[${index}]`;
                quantidade.min = '1';
                quantidade.max = '{{ $maxPecas }}';
                quantidade.value = '1';
                quantidade.required = true;
                quantidade.className = 'w-24 rounded border-gray-300';
                quantidade.setAttribute('aria-label', `Quantidade de ${arquivo.name}`);
                quantidade.addEventListener('input', atualizarTotal);
                label.append(nome, quantidade);
                quantidades.append(label);
            });
            atualizarTotal();
        });
        atualizarTotal();
        function atualizarOpcoes() {
            for (const option of espessura.options) {
                if (!option.value) continue;
                option.hidden = option.disabled = option.dataset.material !== material.value;
            }
            if (espessura.selectedOptions[0]?.disabled) espessura.value = '';
            kerf.placeholder = espessura.selectedOptions[0]?.dataset.kerf
                ? `Padrão: ${espessura.selectedOptions[0].dataset.kerf} mm` : 'Padrão da espessura';
            for (const option of (chapa.options || [])) {
                if (!option.value) continue;
                option.hidden = option.disabled = option.dataset.material !== material.value || option.dataset.espessura !== espessura.value;
            }
            if (chapa.selectedOptions?.[0]?.disabled) chapa.value = '';
            const perfil = espessura.selectedOptions[0];
            const minimo = Number(perfil?.dataset.espacamento || 0);
            for (const campo of [distancia, margem]) {
                campo.min = perfil?.value ? String(minimo) : '0';
                if (perfil?.value && (!campo.value || campo.value === perfilAnterior?.dataset.espacamento || Number(campo.value) < minimo)) campo.value = String(minimo);
            }
            if (perfil?.value && (!kerf.value || kerf.value === perfilAnterior?.dataset.kerf)) kerf.value = perfil.dataset.kerf || '';
            if (perfil?.value && (!leadIn.value || leadIn.value === perfilAnterior?.dataset.leadIn)) leadIn.value = perfil.dataset.leadIn || '';
            if (perfil?.value) perfilAnterior = perfil;
        }
        material.addEventListener('change', atualizarOpcoes);
        espessura.addEventListener('change', atualizarOpcoes);
        atualizarOpcoes();
    </script>
</x-app-layout>
