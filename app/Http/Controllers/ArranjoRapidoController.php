<?php

namespace App\Http\Controllers;

use App\Enums\StatusChapa;
use App\Enums\StatusPedido;
use App\Enums\StatusProcessamento;
use App\Jobs\GerarCncSaida;
use App\Models\Chapa;
use App\Models\EspessuraMaterial;
use App\Models\Material;
use App\Models\Peca;
use App\Models\PedidoChapa;
use App\Models\PedidoCorte;
use App\Services\AreaGeometria;
use App\Services\ArranjoPreview;
use App\Services\CalculadoraTempoCorte;
use App\Services\EstoqueDoArranjo;
use App\Services\GeometriaDxf;
use App\Services\GeometriaNesting;
use App\Services\MotorNesting;
use App\Services\PosicoesArranjo;
use App\Services\ProgramaCnc;
use App\Services\WorkerIndisponivel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class ArranjoRapidoController extends Controller
{
    private const MAX_ARQUIVOS = 20;

    private const MAX_PECAS = 100;

    public function create(ArranjoPreview $previews): View
    {
        $idsSelecionados = collect(old('pecas_existentes', []))->filter(fn ($quantidade) => (int) $quantidade > 0)
            ->keys()->filter(fn ($id) => ctype_digit((string) $id))->map(fn ($id) => (int) $id)->all();

        return view('arranjos.create', [
            'maxArquivos' => self::MAX_ARQUIVOS,
            'maxPecas' => self::MAX_PECAS,
            'materiais' => Material::orderBy('nome')->get(),
            'espessuras' => EspessuraMaterial::with('material')->orderBy('valor_mm')->get(),
            'pecasSelecionadas' => Peca::whereKey($idsSelecionados)
                ->where('status_processamento', StatusProcessamento::Processado->value)
                ->get(['id', 'nome', 'largura_bbox_mm', 'altura_bbox_mm'])
                ->map(fn (Peca $peca) => [
                    'id' => $peca->id, 'nome' => $peca->nome,
                    'largura_bbox_mm' => $peca->largura_bbox_mm,
                    'altura_bbox_mm' => $peca->altura_bbox_mm,
                    'quantidade' => (int) old('pecas_existentes.'.$peca->id, 1),
                ])->all(),
            'chapas' => $previews->todasDisponiveis()->with('material', 'espessura')
                ->orderByRaw("CASE WHEN origem = 'retalho' THEN 0 ELSE 1 END")
                ->orderByRaw('largura_mm * altura_mm')->get(),
            'revisao' => null,
        ]);
    }

    public function revisar(PedidoCorte $pedido): View
    {
        $this->assertRevisavel($pedido);
        $pedido->load('chapa.material', 'chapa.espessura', 'pecas');
        $quantidades = old('substituir_pecas')
            ? old('pecas_existentes', [])
            : $pedido->pecas->mapWithKeys(fn (Peca $peca) => [$peca->id => (int) $peca->pivot->quantidade])->all();
        $ids = collect($quantidades)->filter(fn ($q) => (int) $q > 0)->keys()->all();

        return view('arranjos.create', [
            'maxArquivos' => self::MAX_ARQUIVOS, 'maxPecas' => self::MAX_PECAS,
            'materiais' => collect([$pedido->chapa->material]),
            'espessuras' => collect([$pedido->chapa->espessura]),
            'pecasSelecionadas' => Peca::whereKey($ids)->get()->map(fn (Peca $peca) => [
                'id' => $peca->id, 'nome' => $peca->nome,
                'largura_bbox_mm' => $peca->largura_bbox_mm, 'altura_bbox_mm' => $peca->altura_bbox_mm,
                'quantidade' => (int) $quantidades[$peca->id],
            ])->all(),
            'chapas' => collect(), 'revisao' => $pedido,
        ]);
    }

    private function assertRevisavel(PedidoCorte $pedido): void
    {
        if ($pedido->status !== StatusPedido::Pronto || ! $pedido->estoque_baixado_em || $pedido->concluido_em) {
            throw ValidationException::withMessages(['pedido' => 'Só é possível editar o arranjo antes de iniciar o corte de um pedido pronto.']);
        }
    }

    private function assinaturaPedido(PedidoCorte $pedido): string
    {
        $pedido->loadMissing('pecas', 'chapasInternas');

        return hash('sha256', json_encode([
            $pedido->status->value, $pedido->chapa_id, $pedido->resultado_nesting_json,
            $pedido->retalho_chapa_id, $pedido->cnc_saida, $pedido->dxf_saida,
            $pedido->pecas->map(fn (Peca $peca) => [$peca->id, $peca->pivot->quantidade])->sortBy(0)->values()->all(),
            $pedido->chapasInternas->map(fn (PedidoChapa $plano) => [
                $plano->numero, $plano->chapa_id, $plano->resultado_nesting_json, $plano->retalho_chapa_id,
                $plano->cnc_saida, $plano->dxf_saida,
            ])->all(),
        ], JSON_THROW_ON_ERROR));
    }

    public function buscarPecas(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:1', 'max:100']]);
        $busca = trim($data['q']);
        $pecas = Peca::where('status_processamento', StatusProcessamento::Processado->value)
            ->where(function ($query) use ($busca): void {
                $query->where('nome', 'like', '%'.$busca.'%');
                if (ctype_digit($busca)) {
                    $query->orWhereKey((int) $busca);
                }
            });
        if (ctype_digit($busca)) {
            $pecas->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $busca]);
        }
        $pecas = $pecas->orderBy('nome')->limit(10)
            ->get(['id', 'nome', 'largura_bbox_mm', 'altura_bbox_mm']);

        return response()->json(['pecas' => $pecas]);
    }

    public function store(Request $request, ArranjoPreview $previews, GeometriaDxf $geometria, MotorNesting $motor,
        CalculadoraTempoCorte $calculadora): RedirectResponse
    {
        $arquivos = $request->file('arquivos', []);
        $data = $request->validate([
            'pedido_id' => ['nullable', 'integer', 'exists:pedidos_corte,id'],
            'nome' => ['nullable', 'string', 'max:120'],
            'kerf_mm' => ['nullable', 'numeric', 'between:0,20'],
            'distancia_pecas_mm' => ['nullable', 'numeric', 'between:0,100'],
            'margem_borda_mm' => ['nullable', 'numeric', 'between:0,100'],
            'lead_in_mm' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'substituir_pecas' => ['nullable', 'boolean'],
            'usar_tabs' => ['nullable', 'boolean'],
            'tamanho_tab_mm' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'material_id' => ['required', 'integer', 'exists:materiais,id'],
            'espessura_id' => ['required', 'integer', 'exists:espessuras_material,id'],
            'chapa_id' => ['nullable', 'integer', 'exists:chapas,id'],
            'arquivos' => ['nullable', 'array', 'max:'.self::MAX_ARQUIVOS],
            'arquivos.*' => ['required', 'file', 'extensions:dxf', 'max:5120'],
            'quantidades' => ['nullable', 'array'],
            'quantidades.*' => ['required', 'integer', 'min:1', 'max:'.self::MAX_PECAS],
            'pecas_existentes' => ['nullable', 'array'],
            'pecas_existentes.*' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_PECAS],
        ]);
        $arquivos = $data['arquivos'] ?? [];
        $quantidades = $data['quantidades'] ?? [];
        if (count($quantidades) !== count($arquivos)) {
            throw ValidationException::withMessages(['quantidades' => 'Informe uma quantidade para cada DXF enviado.']);
        }
        foreach (array_keys($arquivos) as $index) {
            if (! array_key_exists($index, $quantidades)) {
                throw ValidationException::withMessages(['quantidades' => 'Informe uma quantidade para cada DXF.']);
            }
        }
        $selecionadas = array_filter($data['pecas_existentes'] ?? [], fn ($quantidade) => (int) $quantidade > 0);
        $revisao = isset($data['pedido_id']) ? PedidoCorte::with('chapa', 'pecas', 'chapasInternas')->findOrFail($data['pedido_id']) : null;
        if ($revisao) {
            $this->assertRevisavel($revisao);
            if ($revisao->chapa->material_id !== (int) $data['material_id'] || $revisao->chapa->espessura_id !== (int) $data['espessura_id']) {
                throw ValidationException::withMessages(['pedido' => 'Material e espessura devem ser os mesmos do pedido original.']);
            }
        }
        if ($revisao && ! ($data['substituir_pecas'] ?? false)) {
            foreach ($revisao->pecas as $pecaExistente) {
                $selecionadas[$pecaExistente->id] = (int) ($selecionadas[$pecaExistente->id] ?? 0) + (int) $pecaExistente->pivot->quantidade;
            }
        }
        if (! $arquivos && ! $selecionadas) {
            throw ValidationException::withMessages(['pecas_existentes' => 'Mantenha pelo menos uma peça no arranjo ou envie um DXF.']);
        }
        if (array_sum($quantidades) + array_sum($selecionadas) > self::MAX_PECAS) {
            throw ValidationException::withMessages(['quantidades' => 'O arranjo aceita até '.self::MAX_PECAS.' peças no total.']);
        }
        $catalogo = Peca::whereKey(array_keys($selecionadas))
            ->where('status_processamento', StatusProcessamento::Processado->value)->get()->keyBy('id');
        if ($catalogo->count() !== count($selecionadas)) {
            throw ValidationException::withMessages(['pecas_existentes' => 'Selecione apenas peças cadastradas e processadas.']);
        }
        $espessura = EspessuraMaterial::whereKey($data['espessura_id'])->where('material_id', $data['material_id'])->first();
        if (! $espessura) {
            throw ValidationException::withMessages(['espessura_id' => 'A espessura não pertence ao material selecionado.']);
        }
        $kerf = isset($data['kerf_mm']) ? (float) $data['kerf_mm'] : (float) $espessura->kerf_mm;
        $minimo = (float) $espessura->espacamento_minimo_mm;
        $espacamento = (float) ($data['distancia_pecas_mm'] ?? $minimo);
        $margem = (float) ($data['margem_borda_mm'] ?? $minimo);
        if ($espacamento < $minimo) {
            throw ValidationException::withMessages(['distancia_pecas_mm' => 'A distância entre peças deve ser de pelo menos '.$minimo.' mm para esta espessura.']);
        }
        if ($margem < $minimo) {
            throw ValidationException::withMessages(['margem_borda_mm' => 'A margem da chapa deve ser de pelo menos '.$minimo.' mm para esta espessura.']);
        }
        if ($espacamento <= $kerf) {
            throw ValidationException::withMessages(['kerf_mm' => 'O kerf deve ser menor que a distância segura entre peças.']);
        }
        $seguranca = ['kerf_mm' => $kerf,
            'espacamento_minimo_mm' => $espacamento,
            'margem_borda_mm' => $margem,
            'distancia_pecas_mm' => $espacamento,
            'lead_in_mm' => (float) ($data['lead_in_mm'] ?? $espessura->lead_in_mm),
            'usar_tabs' => (bool) ($data['usar_tabs'] ?? $revisao?->usar_tabs ?? false),
            'tamanho_tab_mm' => (float) ($data['tamanho_tab_mm'] ?? $revisao?->tamanho_tab_mm ?? 1)];
        if ($revisao && ($data['chapa_id'] ?? null)) {
            throw ValidationException::withMessages(['chapa_id' => 'A revisão começa pelas chapas já reservadas.']);
        }
        if ($data['chapa_id'] ?? null) {
            $previews->escolher((int) $data['material_id'], (int) $espessura->id, (int) $data['chapa_id']);
        }

        set_time_limit(600);
        $previews->limparExpirados();
        $token = (string) Str::uuid();
        $pecas = [];
        try {
            foreach ($arquivos as $index => $file) {
                $path = $file->storeAs('previews/'.$token, $index.'.dxf', 'local');
                if (! $path) {
                    throw new RuntimeException('Não foi possível guardar o DXF temporário.');
                }
                try {
                    $raw = $geometria->extrair($path);
                    $contornos = $kerf > 0
                        ? $geometria->aplicarOffset($raw['contornos'], $kerf / 2)
                        : $raw['contornos'];
                } catch (RuntimeException $error) {
                    throw ValidationException::withMessages(['arquivos' => 'O arquivo "'.$file->getClientOriginalName().'" não pôde ser usado: '.$error->getMessage()]);
                }
                $nome = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                $pecas[] = [
                    'token' => 'preview-'.$index,
                    'nome' => mb_substr($nome ?: 'Peça '.($index + 1), 0, 255),
                    'quantidade' => (int) $quantidades[$index],
                    'arquivo_temporario' => $path,
                    'contornos_originais' => $raw['contornos'],
                    'bounding_box' => $raw['bounding_box'],
                    'contornos_compensados' => $contornos,
                ];
            }
            foreach ($selecionadas as $id => $quantidade) {
                $catalogada = $catalogo->get((int) $id);
                if (! is_array($catalogada->contorno_json) || ! count($catalogada->contorno_json)) {
                    throw ValidationException::withMessages(['pecas_existentes' => 'Uma peça cadastrada não tem geometria válida.']);
                }
                try {
                    $contornos = $kerf > 0
                        ? $geometria->aplicarOffset($catalogada->contorno_json, $kerf / 2)
                        : $catalogada->contorno_json;
                } catch (RuntimeException $error) {
                    throw ValidationException::withMessages(['pecas_existentes' => 'A peça "'.$catalogada->nome.'" não pôde ser preparada para esta espessura: '.$error->getMessage()]);
                }
                $pecas[] = [
                    'token' => 'catalogo-'.$catalogada->id,
                    'nome' => $catalogada->nome,
                    'quantidade' => (int) $quantidade,
                    'peca_id' => $catalogada->id,
                    'peca_updated_at' => $catalogada->updated_at?->toISOString(),
                    'contornos_originais' => $catalogada->contorno_json,
                    'bounding_box' => ['largura_mm' => $catalogada->largura_bbox_mm, 'altura_mm' => $catalogada->altura_bbox_mm],
                    'contornos_compensados' => $contornos,
                ];
            }
            $chapa = isset($data['chapa_id'])
                ? $previews->escolher((int) $data['material_id'], (int) $espessura->id, (int) $data['chapa_id'])
                : null;
            try {
                $generated = $revisao
                    ? $previews->planejarRevisao($revisao, $pecas, $motor, $calculadora, $seguranca)
                    : $previews->planejar((int) $data['material_id'], (int) $espessura->id,
                        $pecas, $motor, $calculadora, $chapa, $seguranca);
            } catch (ConnectionException $error) {
                Log::warning('Motor de otimização indisponível durante preview', ['exception' => $error]);
                throw ValidationException::withMessages(['arranjo' => 'O motor de otimização não respondeu. Aguarde um momento e tente novamente.']);
            } catch (RequestException $error) {
                Log::warning('Motor de otimização recusou o preview', ['exception' => $error]);
                $motivo = $error->response->json('erro');
                throw ValidationException::withMessages(['arranjo' => $error->response->status() === 422 && is_string($motivo)
                        ? 'O arranjo não pôde ser calculado: '.$motivo
                        : 'O motor retornou um erro durante a otimização. Tente novamente.']);
            }
            $previews->salvar($token, [
                'nome' => $revisao ? $revisao->nome : (trim($data['nome'] ?? '') ?: 'Novo arranjo'),
                'pedido_id' => $revisao?->id,
                'pedido_updated_at' => $revisao?->updated_at?->toISOString(),
                'pedido_assinatura' => $revisao ? $this->assinaturaPedido($revisao) : null,
                'user_id' => $request->user()->id,
                'material_id' => (int) $data['material_id'],
                'espessura_id' => (int) $espessura->id,
                'espessura_updated_at' => $espessura->updated_at?->toISOString(),
                ...$seguranca,
                'pecas' => $pecas,
                ...$generated,
            ]);
        } catch (Throwable $error) {
            Storage::disk('local')->deleteDirectory('previews/'.$token);
            if ($error instanceof ValidationException) {
                throw $error;
            }
            if ($error instanceof WorkerIndisponivel) {
                throw ValidationException::withMessages(['arranjo' => $error->getMessage()]);
            }
            Log::warning('Falha ao gerar preview de arranjo rápido', ['exception' => $error]);
            throw ValidationException::withMessages(['arquivos' => 'Não foi possível gerar o preview. Verifique os DXFs e tente novamente.']);
        }

        return redirect()->route('arranjos.show', $token);
    }

    public function show(string $token, Request $request, ArranjoPreview $previews): View
    {
        $preview = $previews->obter($token, $request->user()->id);
        $indice = $request->integer('plano', 0);
        abort_unless(isset($preview['planos'][$indice]), 404);
        $plano = $preview['planos'][$indice];
        $chapa = Chapa::with('material', 'espessura')->findOrFail($plano['chapa_id']);
        $chapas = $previews->disponiveis($preview['material_id'], $preview['espessura_id'])
            ->with('material', 'espessura')
            ->orderByRaw("CASE WHEN origem = 'retalho' THEN 0 ELSE 1 END")
            ->orderByRaw('largura_mm * altura_mm')->get();
        if ($preview['pedido_id'] ?? null) {
            $pedido = PedidoCorte::with('chapa', 'chapasInternas.chapa')->findOrFail($preview['pedido_id']);
            $chapas = collect([$pedido->chapa])->concat($pedido->chapasInternas->pluck('chapa'))
                ->concat($chapas)->unique('id')->values();
        }
        $names = array_column($preview['pecas'], 'nome', 'token');

        return view('arranjos.show', compact('token', 'preview', 'plano', 'indice', 'chapa', 'chapas', 'names'));
    }

    public function data(string $token, Request $request, ArranjoPreview $previews, ProgramaCnc $programa): JsonResponse
    {
        $preview = $previews->obter($token, $request->user()->id);
        $indice = $request->integer('plano', 0);
        abort_unless(isset($preview['planos'][$indice]), 404);
        $plano = $preview['planos'][$indice];
        $chapa = Chapa::with('espessura')->findOrFail($plano['chapa_id']);
        $names = array_column($preview['pecas'], 'nome', 'token');
        $trajetos = [];
        $erroCnc = null;
        if ($plano['resultado']['pecas_posicionadas']) {
            try {
                $trajetos = $programa->preview($chapa, $plano['resultado']['pecas_posicionadas'], $preview);
            } catch (RuntimeException $error) {
                $erroCnc = 'Não há trajetória de entrada segura para o CNC: '.$error->getMessage();
            }
        }

        return response()->json([
            'chapa' => ['largura_mm' => (float) $chapa->largura_mm, 'altura_mm' => (float) $chapa->altura_mm, 'contorno' => $chapa->contorno_json],
            'pecas_posicionadas' => array_map(fn (array $item) => [...$item, 'nome' => $names[$item['id']] ?? $item['id']],
                $plano['resultado']['pecas_posicionadas']),
            'trajetos_cnc' => $trajetos,
            'erro_cnc' => $erroCnc,
            'margem_borda_mm' => (float) ($preview['margem_borda_mm'] ?? 0),
            'distancia_pecas_mm' => (float) ($preview['distancia_pecas_mm'] ?? 0),
            'kerf_mm' => (float) ($preview['kerf_mm'] ?? 0),
            'usar_tabs' => (bool) ($preview['usar_tabs'] ?? false),
        ]);
    }

    public function salvarPosicoes(string $token, Request $request, ArranjoPreview $previews, PosicoesArranjo $validador,
        GeometriaNesting $geometria, MotorNesting $motor, CalculadoraTempoCorte $calculadora,
        ProgramaCnc $programa): JsonResponse
    {
        $data = $request->validate([
            'plano' => ['required', 'integer', 'min:0'],
            'posicoes' => ['required', 'array', 'min:1', 'max:100'],
            'posicoes.*.instancia' => ['required', 'integer', 'min:0'],
            'posicoes.*.id' => ['required'],
            'posicoes.*.x' => ['required', 'numeric', 'min:0'],
            'posicoes.*.y' => ['required', 'numeric', 'min:0'],
            'posicoes.*.rotacao_graus' => ['required', 'numeric', 'between:-3600,3600'],
        ]);

        return Cache::lock('arranjo-preview-lock:'.$token, 30)->block(5, function () use (
            $token, $request, $previews, $validador, $geometria, $motor, $calculadora, $programa, $data
        ): JsonResponse {
            $preview = $previews->obter($token, $request->user()->id);
            $indice = (int) $data['plano'];
            abort_unless(isset($preview['planos'][$indice]), 404);
            $plano = $preview['planos'][$indice];
            $chapa = Chapa::with('espessura')->findOrFail($plano['chapa_id']);
            $posicoes = $validador->validar($data['posicoes'], $plano['resultado']['pecas_posicionadas'], $chapa, $geometria);
            $avaliado = $motor->avaliar($chapa, $posicoes, $preview);
            try {
                $trajetos = $programa->preview($chapa, $posicoes, $preview);
            } catch (RuntimeException $error) {
                throw ValidationException::withMessages(['posicoes' => $error->getMessage()]);
            }
            $plano['resultado']['pecas_posicionadas'] = $posicoes;
            $plano['resultado']['area_utilizada_pct'] = $avaliado['area_utilizada_pct'];
            $plano['resultado']['sobra_contorno'] = $avaliado['sobra_contorno'];
            $plano['tempo_corte_estimado_seg'] = $calculadora->calcular($posicoes, $chapa->espessura, $preview);
            $preview['planos'][$indice] = $plano;
            $previews->salvar($token, $preview);

            return response()->json(['tempo_corte_estimado_seg' => $plano['tempo_corte_estimado_seg'],
                'area_utilizada_pct' => $avaliado['area_utilizada_pct'], 'trajetos_cnc' => $trajetos]);
        });
    }

    public function tentarOutraChapa(string $token, Request $request, ArranjoPreview $previews, MotorNesting $motor,
        CalculadoraTempoCorte $calculadora, GeometriaDxf $geometria): RedirectResponse
    {
        $data = $request->validate([
            'chapa_id' => ['required', 'integer', 'exists:chapas,id'],
            'kerf_mm' => ['nullable', 'numeric', 'between:0,20'],
            'distancia_pecas_mm' => ['nullable', 'numeric', 'between:0,100'],
            'margem_borda_mm' => ['nullable', 'numeric', 'between:0,100'],
            'lead_in_mm' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'usar_tabs' => ['nullable', 'boolean'],
            'tamanho_tab_mm' => ['nullable', 'numeric', 'gt:0', 'max:100'],
        ]);
        $preview = $previews->obter($token, $request->user()->id);
        $espessura = EspessuraMaterial::findOrFail($preview['espessura_id']);
        if ($espessura->updated_at?->toISOString() !== $preview['espessura_updated_at']) {
            throw ValidationException::withMessages(['chapa_id' => 'A espessura mudou. Envie o lote novamente para recalcular o corte.']);
        }
        $chapa = ($preview['pedido_id'] ?? null)
            ? PedidoCorte::findOrFail($preview['pedido_id'])->chapa
            : $previews->escolher($preview['material_id'], $preview['espessura_id'], (int) $data['chapa_id']);
        $chapa->load('espessura');
        $kerf = isset($data['kerf_mm']) ? (float) $data['kerf_mm'] : (float) ($preview['kerf_mm'] ?? $espessura->kerf_mm);
        $minimo = (float) $espessura->espacamento_minimo_mm;
        $espacamento = (float) ($data['distancia_pecas_mm'] ?? $preview['distancia_pecas_mm'] ?? $minimo);
        $margem = (float) ($data['margem_borda_mm'] ?? $preview['margem_borda_mm'] ?? $minimo);
        if ($espacamento < $minimo) {
            throw ValidationException::withMessages(['distancia_pecas_mm' => 'A distância entre peças deve ser de pelo menos '.$minimo.' mm para esta espessura.']);
        }
        if ($margem < $minimo) {
            throw ValidationException::withMessages(['margem_borda_mm' => 'A margem da chapa deve ser de pelo menos '.$minimo.' mm para esta espessura.']);
        }
        if ($espacamento <= $kerf) {
            throw ValidationException::withMessages(['kerf_mm' => 'O kerf deve ser menor que a distância segura entre peças.']);
        }
        $seguranca = ['kerf_mm' => $kerf,
            'espacamento_minimo_mm' => $espacamento,
            'margem_borda_mm' => $margem,
            'distancia_pecas_mm' => $espacamento,
            'lead_in_mm' => (float) ($data['lead_in_mm'] ?? $preview['lead_in_mm'] ?? $espessura->lead_in_mm),
            'usar_tabs' => (bool) ($data['usar_tabs'] ?? $preview['usar_tabs'] ?? false),
            'tamanho_tab_mm' => (float) ($data['tamanho_tab_mm'] ?? $preview['tamanho_tab_mm'] ?? 1)];
        $pecas = $preview['pecas'];
        if (abs($kerf - (float) ($preview['kerf_mm'] ?? $espessura->kerf_mm)) > 1e-9) {
            try {
                foreach ($pecas as &$peca) {
                    $peca['contornos_compensados'] = $kerf > 0
                        ? $geometria->aplicarOffset($peca['contornos_originais'], $kerf / 2)
                        : $peca['contornos_originais'];
                }
                unset($peca);
            } catch (RuntimeException $error) {
                throw ValidationException::withMessages(['kerf_mm' => $error->getMessage()]);
            }
        }
        try {
            set_time_limit(600);
            $generated = ($preview['pedido_id'] ?? null)
                ? $previews->planejarRevisao(PedidoCorte::findOrFail($preview['pedido_id']),
                    $pecas, $motor, $calculadora, $seguranca)
                : $previews->planejar($preview['material_id'], $preview['espessura_id'],
                    $pecas, $motor, $calculadora, $chapa, $seguranca);
        } catch (Throwable $error) {
            Log::warning('Falha ao recalcular chapa de arranjo rápido', ['exception' => $error]);
            if ($error instanceof WorkerIndisponivel) {
                throw ValidationException::withMessages(['arranjo' => $error->getMessage()]);
            }
            throw ValidationException::withMessages(['chapa_id' => 'Não foi possível recalcular o arranjo nesta chapa. Tente outra chapa.']);
        }
        $previews->salvar($token, [...$preview, 'pecas' => $pecas, ...$seguranca, ...$generated]);

        return redirect()->route('arranjos.show', $token)->with('status', 'Lote recalculado começando pela chapa escolhida.');
    }

    public function aceitar(string $token, Request $request, ArranjoPreview $previews, AreaGeometria $area,
        EstoqueDoArranjo $estoque, ProgramaCnc $programa): RedirectResponse
    {
        return Cache::lock('arranjo-preview-lock:'.$token, 30)->block(5, function () use ($token, $request, $previews, $area, $estoque, $programa): RedirectResponse {
            $preview = $previews->obter($token, $request->user()->id);
            if (count($preview['pecas_nao_posicionadas'])) {
                throw ValidationException::withMessages(['arranjo' => 'Ainda há peças sem chapa disponível. Recalcule o lote após cadastrar mais chapas.']);
            }
            $disk = Storage::disk('local');
            foreach ($preview['pecas'] as $peca) {
                if (isset($peca['peca_id'])) {
                    continue;
                }
                if (! $disk->exists($peca['arquivo_temporario'])) {
                    throw ValidationException::withMessages(['arranjo' => 'Um arquivo temporário expirou. Envie o lote novamente.']);
                }
            }
            foreach ($preview['planos'] as $plano) {
                try {
                    $programa->preview(Chapa::with('espessura')->findOrFail($plano['chapa_id']),
                        $plano['resultado']['pecas_posicionadas'], $preview);
                } catch (RuntimeException $error) {
                    throw ValidationException::withMessages(['arranjo' => $error->getMessage()]);
                }
            }
            if ($preview['pedido_id'] ?? null) {
                return $this->aceitarRevisao($token, $preview, $previews, $area, $estoque);
            }
            $copied = [];
            try {
                $pedidos = DB::transaction(function () use ($preview, $token, $disk, $area, $estoque, &$copied): array {
                    $espessura = EspessuraMaterial::whereKey($preview['espessura_id'])->lockForUpdate()->firstOrFail();
                    if ($espessura->updated_at?->toISOString() !== $preview['espessura_updated_at']) {
                        throw ValidationException::withMessages(['arranjo' => 'A espessura mudou. Gere um novo preview antes de aceitar.']);
                    }
                    $usos = array_count_values(array_column($preview['planos'], 'chapa_id'));
                    $chapas = Chapa::whereKey(array_keys($usos))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                    foreach ($preview['planos'] as $plano) {
                        $chapa = $chapas->get($plano['chapa_id']);
                        if (! $chapa || $chapa->status !== StatusChapa::Disponivel
                            || $chapa->quantidade_disponivel - $chapa->pedidos()
                                ->whereNull('estoque_baixado_em')->whereIn('status', ArranjoPreview::STATUS_COM_CHAPA_ALOCADA)->count() < $usos[$chapa->id]
                            || $chapa->material_id !== $preview['material_id'] || $chapa->espessura_id !== $preview['espessura_id']
                            || $chapa->updated_at?->toISOString() !== $plano['chapa_updated_at']) {
                            throw ValidationException::withMessages(['arranjo' => 'O estoque de chapas mudou. Recalcule o lote antes de aceitar.']);
                        }
                    }
                    $ids = [];
                    foreach ($preview['pecas'] as $index => $peca) {
                        if (isset($peca['peca_id'])) {
                            $catalogada = Peca::whereKey($peca['peca_id'])->lockForUpdate()->firstOrFail();
                            if ($catalogada->status_processamento !== StatusProcessamento::Processado
                                || $catalogada->updated_at?->toISOString() !== $peca['peca_updated_at']) {
                                throw ValidationException::withMessages(['arranjo' => 'Uma peça cadastrada mudou. Gere um novo arranjo.']);
                            }
                            $ids[$peca['token']] = $catalogada->id;

                            continue;
                        }
                        $definitivo = 'pecas/'.$token.'-'.$index.'.dxf';
                        if (! $disk->copy($peca['arquivo_temporario'], $definitivo)) {
                            throw new RuntimeException('Falha ao guardar o DXF definitivo.');
                        }
                        $copied[] = $definitivo;
                        $nova = Peca::create([
                            'nome' => $peca['nome'], 'arquivo_dxf_original' => $definitivo,
                            'contorno_json' => $peca['contornos_originais'],
                            'largura_bbox_mm' => $peca['bounding_box']['largura_mm'],
                            'altura_bbox_mm' => $peca['bounding_box']['altura_mm'],
                            'status_processamento' => StatusProcessamento::Processado,
                        ]);
                        $ids[$peca['token']] = $nova->id;
                    }
                    $primeiro = $preview['planos'][0];
                    $chapaInicial = $chapas->get($primeiro['chapa_id']);
                    $resultadoInicial = $primeiro['resultado'];
                    foreach ($resultadoInicial['pecas_posicionadas'] as &$item) {
                        $item['id'] = $ids[$item['id']];
                    }
                    unset($item);
                    $resultadoInicial['pecas_nao_posicionadas'] = [];
                    $pedido = PedidoCorte::create([
                        'nome' => $preview['nome'], 'chapa_id' => $chapaInicial->id,
                        'status' => StatusPedido::Pronto, 'resultado_nesting_json' => $resultadoInicial,
                        'usar_tabs' => (bool) ($preview['usar_tabs'] ?? false),
                        'tamanho_tab_mm' => (float) ($preview['tamanho_tab_mm'] ?? 1),
                        'tempo_corte_estimado_seg' => array_sum(array_column($preview['planos'], 'tempo_corte_estimado_seg')),
                        'sobra_aproveitavel_json' => $resultadoInicial['sobra_contorno'] ?? null,
                        'area_chapa_mm2' => array_sum(array_map(fn ($plano) => $area->chapa(
                            $chapas->get($plano['chapa_id'])->contorno_json ?? [],
                            (float) $chapas->get($plano['chapa_id'])->largura_mm,
                            (float) $chapas->get($plano['chapa_id'])->altura_mm), $preview['planos'])),
                        'area_pecas_mm2' => array_sum(array_map(fn ($plano) => $area->pecas($plano['resultado']['pecas_posicionadas']), $preview['planos'])),
                    ]);
                    $quantidadesLote = [];
                    foreach ($preview['planos'] as $plano) {
                        foreach ($plano['resultado']['pecas_posicionadas'] as $item) {
                            $pecaId = $ids[$item['id']];
                            $quantidadesLote[$pecaId] = ($quantidadesLote[$pecaId] ?? 0) + 1;
                        }
                    }
                    $pedido->pecas()->sync(collect($quantidadesLote)->mapWithKeys(
                        fn ($quantidade, $id) => [$id => ['quantidade' => $quantidade]]
                    )->all());
                    $estoque->baixarNaExportacao($pedido, $chapaInicial);
                    foreach ($preview['planos'] as $numero => $plano) {
                        if ($numero === 0) {
                            continue;
                        }
                        $chapa = $chapas->get($plano['chapa_id']);
                        $result = $plano['resultado'];
                        foreach ($result['pecas_posicionadas'] as &$item) {
                            $item['id'] = $ids[$item['id']];
                        }
                        unset($item);
                        $result['pecas_nao_posicionadas'] = [];
                        $chapaInterna = PedidoChapa::create([
                            'pedido_corte_id' => $pedido->id, 'numero' => $numero + 1,
                            'chapa_id' => $chapa->id,
                            'resultado_nesting_json' => $result,
                            'tempo_corte_estimado_seg' => $plano['tempo_corte_estimado_seg'],
                            'sobra_aproveitavel_json' => $result['sobra_contorno'] ?? null,
                            'area_chapa_mm2' => $area->chapa($chapa->contorno_json ?? [], (float) $chapa->largura_mm, (float) $chapa->altura_mm),
                            'area_pecas_mm2' => $area->pecas($result['pecas_posicionadas']),
                        ]);
                        $estoque->baixarChapaInterna($pedido, $chapaInterna, $chapa);
                    }
                    $estoque->atualizarTotaisLote($pedido->refresh());

                    return [$pedido->id];
                });
            } catch (Throwable $error) {
                $disk->delete($copied);
                throw $error;
            }
            $previews->esquecer($token);
            foreach ($pedidos as $pedidoId) {
                GerarCncSaida::dispatch($pedidoId);
            }

            return redirect()->route('pedidos.show', $pedidos[0])->with('status',
                'Pedido exportado com '.count($preview['planos']).' chapa(s). Os arquivos CNC estão sendo gerados.');
        });
    }

    private function aceitarRevisao(string $token, array $preview, ArranjoPreview $previews,
        AreaGeometria $area, EstoqueDoArranjo $estoque): RedirectResponse
    {
        $disk = Storage::disk('local');
        $copied = [];
        try {
            [$pedidoId, $arquivosAntigos] = DB::transaction(function () use ($preview, $token, $disk, $area, $estoque, &$copied): array {
                $pedido = PedidoCorte::whereKey($preview['pedido_id'])->lockForUpdate()->firstOrFail();
                $this->assertRevisavel($pedido);
                if ($pedido->updated_at?->toISOString() !== $preview['pedido_updated_at']
                    || $this->assinaturaPedido($pedido) !== $preview['pedido_assinatura']) {
                    throw ValidationException::withMessages(['arranjo' => 'O pedido mudou desde a prévia. Refaça a revisão.']);
                }
                $pedido->load('chapasInternas');
                $espessura = EspessuraMaterial::whereKey($preview['espessura_id'])->lockForUpdate()->firstOrFail();
                if ($espessura->updated_at?->toISOString() !== $preview['espessura_updated_at']) {
                    throw ValidationException::withMessages(['arranjo' => 'A configuração de corte mudou. Refaça a revisão.']);
                }
                $reservadas = collect([$pedido->chapa_id])->concat($pedido->chapasInternas->pluck('chapa_id'))->countBy()->all();
                $usos = array_count_values(array_column($preview['planos'], 'chapa_id'));
                $chapas = Chapa::whereKey(array_unique(array_merge(array_keys($reservadas), array_keys($usos))))
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                foreach ($preview['planos'] as $plano) {
                    $chapa = $chapas->get($plano['chapa_id']);
                    $livres = $chapa?->quantidade_disponivel ?? 0;
                    $livres -= $chapa?->pedidos()->whereNull('estoque_baixado_em')
                        ->whereIn('status', ArranjoPreview::STATUS_COM_CHAPA_ALOCADA)->count() ?? 0;
                    if (! $chapa || $chapa->material_id !== $preview['material_id']
                        || $chapa->espessura_id !== $preview['espessura_id']
                        || $chapa->updated_at?->toISOString() !== $plano['chapa_updated_at']
                        || $livres + ($reservadas[$chapa->id] ?? 0) < $usos[$chapa->id]) {
                        throw ValidationException::withMessages(['arranjo' => 'O estoque de chapas mudou. Refaça a revisão.']);
                    }
                }
                $ids = [];
                foreach ($preview['pecas'] as $index => $peca) {
                    if (isset($peca['peca_id'])) {
                        $catalogada = Peca::whereKey($peca['peca_id'])->lockForUpdate()->firstOrFail();
                        if ($catalogada->status_processamento !== StatusProcessamento::Processado
                            || $catalogada->updated_at?->toISOString() !== $peca['peca_updated_at']) {
                            throw ValidationException::withMessages(['arranjo' => 'Uma peça mudou. Refaça a revisão.']);
                        }
                        $ids[$peca['token']] = $catalogada->id;

                        continue;
                    }
                    $definitivo = 'pecas/'.$token.'-'.$index.'.dxf';
                    if (! $disk->copy($peca['arquivo_temporario'], $definitivo)) {
                        throw new RuntimeException('Falha ao guardar o DXF definitivo.');
                    }
                    $copied[] = $definitivo;
                    $nova = Peca::create(['nome' => $peca['nome'], 'arquivo_dxf_original' => $definitivo,
                        'contorno_json' => $peca['contornos_originais'],
                        'largura_bbox_mm' => $peca['bounding_box']['largura_mm'],
                        'altura_bbox_mm' => $peca['bounding_box']['altura_mm'],
                        'status_processamento' => StatusProcessamento::Processado]);
                    $ids[$peca['token']] = $nova->id;
                }
                $arquivosAntigos = collect([$pedido->cnc_saida, $pedido->dxf_saida])
                    ->concat($pedido->chapasInternas->flatMap(fn (PedidoChapa $plano) => [$plano->cnc_saida, $plano->dxf_saida]))
                    ->filter()->all();
                $estoque->reverterAoExcluir($pedido);
                $pedido->chapasInternas()->delete();
                $primeiro = $preview['planos'][0];
                $resultado = $primeiro['resultado'];
                foreach ($resultado['pecas_posicionadas'] as &$item) {
                    $item['id'] = $ids[$item['id']];
                }
                unset($item);
                $resultado['pecas_nao_posicionadas'] = [];
                $pedido->update(['chapa_id' => $primeiro['chapa_id'], 'resultado_nesting_json' => $resultado,
                    'sobra_aproveitavel_json' => $resultado['sobra_contorno'] ?? null,
                    'tempo_corte_estimado_seg' => array_sum(array_column($preview['planos'], 'tempo_corte_estimado_seg')),
                    'tempo_corte_real_seg' => null, 'retalho_chapa_id' => null,
                    'cnc_saida' => null, 'dxf_saida' => null, 'erro_mensagem' => null,
                    'estoque_baixado_em' => null, 'exportado_em' => null,
                    'usar_tabs' => (bool) ($preview['usar_tabs'] ?? false),
                    'tamanho_tab_mm' => (float) ($preview['tamanho_tab_mm'] ?? 1),
                    'area_chapa_mm2' => array_sum(array_map(fn ($plano) => $area->chapa(
                        $chapas->get($plano['chapa_id'])->contorno_json ?? [],
                        (float) $chapas->get($plano['chapa_id'])->largura_mm,
                        (float) $chapas->get($plano['chapa_id'])->altura_mm), $preview['planos'])),
                    'area_pecas_mm2' => array_sum(array_map(fn ($plano) => $area->pecas($plano['resultado']['pecas_posicionadas']), $preview['planos'])),
                ]);
                $quantidades = [];
                foreach ($preview['planos'] as $plano) {
                    foreach ($plano['resultado']['pecas_posicionadas'] as $item) {
                        $id = $ids[$item['id']];
                        $quantidades[$id] = ($quantidades[$id] ?? 0) + 1;
                    }
                }
                $pedido->pecas()->sync(collect($quantidades)->mapWithKeys(
                    fn ($q, $id) => [$id => ['quantidade' => $q]])->all());
                $estoque->baixarNaExportacao($pedido, $chapas->get($primeiro['chapa_id'])->refresh());
                foreach ($preview['planos'] as $numero => $plano) {
                    if ($numero === 0) {
                        continue;
                    }
                    $result = $plano['resultado'];
                    foreach ($result['pecas_posicionadas'] as &$item) {
                        $item['id'] = $ids[$item['id']];
                    }
                    unset($item);
                    $result['pecas_nao_posicionadas'] = [];
                    $chapa = $chapas->get($plano['chapa_id'])->refresh();
                    $interna = PedidoChapa::create(['pedido_corte_id' => $pedido->id, 'numero' => $numero + 1,
                        'chapa_id' => $chapa->id, 'resultado_nesting_json' => $result,
                        'tempo_corte_estimado_seg' => $plano['tempo_corte_estimado_seg'],
                        'sobra_aproveitavel_json' => $result['sobra_contorno'] ?? null,
                        'area_chapa_mm2' => $area->chapa($chapa->contorno_json ?? [], (float) $chapa->largura_mm, (float) $chapa->altura_mm),
                        'area_pecas_mm2' => $area->pecas($result['pecas_posicionadas'])]);
                    $estoque->baixarChapaInterna($pedido, $interna, $chapa);
                }
                $estoque->atualizarTotaisLote($pedido->refresh());

                return [$pedido->id, $arquivosAntigos];
            });
        } catch (Throwable $error) {
            $disk->delete($copied);
            throw $error;
        }
        $previews->esquecer($token);
        $disk->delete($arquivosAntigos);
        GerarCncSaida::dispatch($pedidoId);

        return redirect()->route('pedidos.show', $pedidoId)->with('status',
            'Arranjo revisado no mesmo pedido. CNCs das '.count($preview['planos']).' chapa(s) estão sendo gerados.');
    }
}
