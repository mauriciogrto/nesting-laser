<?php

namespace App\Http\Controllers;

use App\Enums\StatusPedido;
use App\Jobs\GerarCncSaida;
use App\Models\Chapa;
use App\Models\PedidoCorte;
use App\Services\CalculadoraTempoCorte;
use App\Services\EstoqueDoArranjo;
use App\Services\GeometriaNesting;
use App\Services\MotorNesting;
use App\Services\PosicoesArranjo;
use App\Services\ProgramaCnc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class EditorPedidoController extends Controller
{
    public function show(Request $request, PedidoCorte $pedido): View
    {
        $this->assertReady($pedido);
        $numero = max(1, $request->integer('chapa', 1));
        $pedido = $this->visaoDaChapa($pedido, $numero);

        return view('pedidos.editor', compact('pedido', 'numero'));
    }

    public function data(Request $request, PedidoCorte $pedido, ProgramaCnc $programa): JsonResponse
    {
        $this->assertReady($pedido);
        $pedido = $this->visaoDaChapa($pedido, max(1, $request->integer('chapa', 1)));
        $pedido->load('pecas');
        $pedido->chapa->loadMissing('espessura');
        $seguranca = $this->seguranca($pedido);
        $nomes = $pedido->pecas->pluck('nome', 'id');
        $trajetos = [];
        $erroCnc = null;
        try {
            $trajetos = $programa->preview($pedido->chapa, $pedido->resultado_nesting_json['pecas_posicionadas'],
                $seguranca);
        } catch (RuntimeException $error) {
            $erroCnc = 'Não há trajetória de entrada segura para o CNC: '.$error->getMessage();
        }

        return response()->json([
            'chapa' => [
                'largura_mm' => (float) $pedido->chapa->largura_mm,
                'altura_mm' => (float) $pedido->chapa->altura_mm,
                'contorno' => $pedido->chapa->contorno_json,
            ],
            'pecas_posicionadas' => array_map(fn (array $item) => [...$item,
                'nome' => $nomes->get($item['id'], 'Peça '.$item['id'])],
                $pedido->resultado_nesting_json['pecas_posicionadas']),
            'trajetos_cnc' => $trajetos,
            'erro_cnc' => $erroCnc,
            'margem_borda_mm' => $seguranca['margem_borda_mm'],
            'distancia_pecas_mm' => $seguranca['distancia_pecas_mm'],
            'kerf_mm' => (float) ($pedido->resultado_nesting_json['kerf_mm'] ?? 0),
            'usar_tabs' => (bool) $pedido->usar_tabs,
            'tempo_corte_estimado_seg' => $pedido->tempo_corte_estimado_seg,
        ]);
    }

    public function recalcular(Request $request, PedidoCorte $pedido, CalculadoraTempoCorte $calculadora,
        GeometriaNesting $geometria, PosicoesArranjo $validador, MotorNesting $motor,
        ProgramaCnc $programa): JsonResponse
    {
        $this->assertReady($pedido);
        $pedido = $this->visaoDaChapa($pedido, max(1, $request->integer('chapa', 1)));
        $positions = $this->positions($request, $pedido, $geometria, $validador);
        $seguranca = $this->seguranca($pedido);
        $avaliado = $motor->avaliar($pedido->chapa, $positions, $seguranca);
        $seconds = $calculadora->calcular($positions, $pedido->chapa->espessura, $seguranca);

        return response()->json(['tempo_corte_estimado_seg' => $seconds,
            'area_utilizada_pct' => $avaliado['area_utilizada_pct'],
            'trajetos_cnc' => $programa->preview($pedido->chapa->load('espessura'), $positions,
                $seguranca)]);
    }

    public function organizar(Request $request, PedidoCorte $pedido, MotorNesting $motor, GeometriaNesting $geometria,
        PosicoesArranjo $validador, CalculadoraTempoCorte $calculadora, ProgramaCnc $programa): JsonResponse
    {
        $this->assertReady($pedido);
        $pedido = $this->visaoDaChapa($pedido, max(1, $request->integer('chapa', 1)));
        $pedido->chapa->loadMissing('espessura');
        $seguranca = $this->seguranca($pedido);
        $original = $pedido->resultado_nesting_json['pecas_posicionadas'] ?? [];
        $grupos = collect($original)->groupBy(fn (array $item) => (string) $item['id']);
        $pecas = $grupos->map(fn ($items) => [
            'id' => $items->first()['id'], 'quantidade' => $items->count(),
            'contornos' => $items->first()['contornos'],
        ])->values()->all();
        $result = $motor->executar($pedido->chapa, $pecas, $seguranca);
        if (count($result['pecas_posicionadas']) !== count($original)) {
            throw ValidationException::withMessages(['arranjo' => 'A nova organização não acomodou todas as peças. O arranjo atual foi mantido.']);
        }
        $instancias = $grupos->map(fn ($items) => $items->pluck('instancia')->values()->all())->all();
        $inputs = [];
        foreach ($result['pecas_posicionadas'] as $item) {
            $key = (string) $item['id'];
            $inputs[] = ['id' => $item['id'], 'instancia' => array_shift($instancias[$key]),
                'x' => $item['x'], 'y' => $item['y'], 'rotacao_graus' => $item['rotacao_graus']];
        }
        $positions = $validador->validar($inputs, $original, $pedido->chapa, $geometria);
        $avaliado = $motor->avaliar($pedido->chapa, $positions, $seguranca);
        $areaRetalho = static function (?array $contorno): float {
            if (! $contorno || count($contorno) < 4) {
                return 0;
            }

            return abs(($contorno[1][0] - $contorno[0][0]) * ($contorno[2][1] - $contorno[1][1]));
        };
        // Recalibrating an old order must be possible even when the safer
        // clearance reduces its reusable remnant. Only ordinary reorganization
        // requires a strictly larger remnant.
        $previousSpacing = (float) ($pedido->resultado_nesting_json['espacamento_minimo_mm']
            ?? $pedido->resultado_nesting_json['distancia_pecas_mm'] ?? 0);
        $previousMargin = (float) ($pedido->resultado_nesting_json['margem_borda_mm'] ?? 0);
        $safetyChanged = $seguranca['distancia_pecas_mm'] > $previousSpacing + 0.001
            || $seguranca['margem_borda_mm'] > $previousMargin + 0.001;
        if (! $safetyChanged && $areaRetalho($avaliado['sobra_contorno'] ?? null) <= $areaRetalho($pedido->sobra_aproveitavel_json) + 0.01) {
            throw ValidationException::withMessages(['arranjo' => 'Nenhum arranjo com retalho maior foi encontrado. O pedido atual foi mantido.']);
        }
        try {
            $trajetos = $programa->preview($pedido->chapa, $positions, $seguranca);
        } catch (RuntimeException $error) {
            throw ValidationException::withMessages(['arranjo' => $error->getMessage()]);
        }

        return response()->json(['posicoes' => $inputs, 'trajetos_cnc' => $trajetos,
            'tempo_corte_estimado_seg' => $calculadora->calcular($positions, $pedido->chapa->espessura, $seguranca),
            'area_utilizada_pct' => $avaliado['area_utilizada_pct']]);
    }

    public function confirmar(Request $request, PedidoCorte $pedido, CalculadoraTempoCorte $calculadora,
        GeometriaNesting $geometria, PosicoesArranjo $validador, MotorNesting $motor,
        EstoqueDoArranjo $estoque, ProgramaCnc $programa): JsonResponse
    {
        if ($request->integer('chapa', 1) > 1) {
            return $this->confirmarChapaInterna($request, $pedido, $calculadora, $geometria,
                $validador, $motor, $estoque, $programa);
        }
        $result = DB::transaction(function () use ($request, $pedido, $calculadora, $geometria, $validador, $motor, $estoque, $programa): array {
            $locked = PedidoCorte::with('chapa.espessura')->whereKey($pedido->id)->lockForUpdate()->firstOrFail();
            $this->assertReady($locked);
            $positions = $this->positions($request, $locked, $geometria, $validador);
            $seguranca = $this->seguranca($locked);
            $avaliado = $motor->avaliar($locked->chapa, $positions, $seguranca);
            try {
                $programa->preview($locked->chapa, $positions, $seguranca);
            } catch (RuntimeException $error) {
                throw ValidationException::withMessages(['posicoes' => $error->getMessage()]);
            }
            $result = $seguranca;
            $result['pecas_posicionadas'] = $positions;
            $result['area_utilizada_pct'] = $avaliado['area_utilizada_pct'];
            $result['sobra_contorno'] = $avaliado['sobra_contorno'];
            $seconds = $calculadora->calcular($positions, $locked->chapa->espessura, $seguranca);
            $totalSeconds = $seconds + (int) $locked->chapasInternas()->sum('tempo_corte_estimado_seg');
            $oldCnc = $locked->cnc_saida;
            $oldDxf = $locked->dxf_saida;
            $locked->update([
                'resultado_nesting_json' => $result,
                'tempo_corte_estimado_seg' => $totalSeconds,
                'sobra_aproveitavel_json' => $avaliado['sobra_contorno'],
                'cnc_saida' => null,
                'dxf_saida' => null,
                'status' => $locked->estoque_baixado_em ? StatusPedido::Pronto : StatusPedido::EmProducao,
            ]);
            if ($locked->estoque_baixado_em) {
                $chapa = Chapa::whereKey($locked->chapa_id)->lockForUpdate()->firstOrFail();
                $estoque->sincronizarRetalhoECustos($locked, $chapa);
            } else {
                $chapa = Chapa::whereKey($locked->chapa_id)->lockForUpdate()->firstOrFail();
                $estoque->baixarNaExportacao($locked, $chapa);
            }

            return ['status' => $locked->status->value, 'tempo_corte_estimado_seg' => $seconds,
                'old_cnc' => $oldCnc, 'old_dxf' => $oldDxf];
        });
        Storage::disk('local')->delete(array_filter([$result['old_cnc'], $result['old_dxf']]));
        GerarCncSaida::dispatch($pedido->id);
        unset($result['old_cnc'], $result['old_dxf']);

        return response()->json($result);
    }

    private function confirmarChapaInterna(Request $request, PedidoCorte $pedido,
        CalculadoraTempoCorte $calculadora, GeometriaNesting $geometria, PosicoesArranjo $validador,
        MotorNesting $motor, EstoqueDoArranjo $estoque, ProgramaCnc $programa): JsonResponse
    {
        $numero = $request->integer('chapa');
        $result = DB::transaction(function () use ($request, $pedido, $numero, $calculadora,
            $geometria, $validador, $motor, $estoque, $programa): array {
            $locked = PedidoCorte::whereKey($pedido->id)->lockForUpdate()->firstOrFail();
            $this->assertReady($locked);
            $plano = $locked->chapasInternas()->with('chapa.espessura')
                ->where('numero', $numero)->lockForUpdate()->firstOrFail();
            $visao = $this->visaoDaChapa($locked, $numero);
            $positions = $this->positions($request, $visao, $geometria, $validador);
            $seguranca = $this->seguranca($visao);
            $avaliado = $motor->avaliar($plano->chapa, $positions, $seguranca);
            try {
                $programa->preview($plano->chapa, $positions, $seguranca);
            } catch (RuntimeException $error) {
                throw ValidationException::withMessages(['posicoes' => $error->getMessage()]);
            }
            $novo = [...$seguranca, 'pecas_posicionadas' => $positions,
                'area_utilizada_pct' => $avaliado['area_utilizada_pct'],
                'sobra_contorno' => $avaliado['sobra_contorno']];
            $seconds = $calculadora->calcular($positions, $plano->chapa->espessura, $seguranca);
            $oldFiles = array_filter([$plano->cnc_saida, $plano->dxf_saida]);
            $oldSeconds = $plano->tempo_corte_estimado_seg;
            $plano->update(['resultado_nesting_json' => $novo,
                'tempo_corte_estimado_seg' => $seconds,
                'sobra_aproveitavel_json' => $avaliado['sobra_contorno'],
                'cnc_saida' => null, 'dxf_saida' => null]);
            $locked->update(['tempo_corte_estimado_seg' => $locked->tempo_corte_estimado_seg - $oldSeconds + $seconds]);
            $estoque->sincronizarChapaInterna($locked, $plano, $plano->chapa);

            return ['status' => $locked->status->value, 'tempo_corte_estimado_seg' => $seconds,
                'files' => $oldFiles];
        });
        Storage::disk('local')->delete($result['files']);
        GerarCncSaida::dispatch($pedido->id);
        unset($result['files']);

        return response()->json($result);
    }

    private function visaoDaChapa(PedidoCorte $pedido, int $numero): PedidoCorte
    {
        if ($numero === 1) {
            if (! $pedido->chapasInternas()->exists()) {
                return $pedido;
            }
            $visao = clone $pedido;
            $visao->tempo_corte_estimado_seg = max(0, (int) $pedido->tempo_corte_estimado_seg
                - (int) $pedido->chapasInternas()->sum('tempo_corte_estimado_seg'));

            return $visao;
        }
        $plano = $pedido->chapasInternas()->with('chapa.espessura')->where('numero', $numero)->firstOrFail();
        $visao = clone $pedido;
        $visao->setRelation('chapa', $plano->chapa);
        $visao->resultado_nesting_json = $plano->resultado_nesting_json;
        $visao->sobra_aproveitavel_json = $plano->sobra_aproveitavel_json;
        $visao->tempo_corte_estimado_seg = $plano->tempo_corte_estimado_seg;
        $visao->estoque_baixado_em = $plano->estoque_baixado_em;

        return $visao;
    }

    private function assertReady(PedidoCorte $pedido): void
    {
        abort_unless($pedido->status === StatusPedido::Pronto, 409, 'O pedido deve estar pronto para abrir o editor.');
    }

    private function seguranca(PedidoCorte $pedido): array
    {
        $result = $pedido->resultado_nesting_json ?? [];
        $espessura = $pedido->chapa?->espessura;
        $espacamento = max((float) ($result['espacamento_minimo_mm'] ?? $result['distancia_pecas_mm'] ?? 0),
            (float) ($espessura?->espacamento_minimo_mm ?? 0));
        if ($espacamento <= (float) ($result['kerf_mm'] ?? $espessura?->kerf_mm ?? 0)) {
            throw ValidationException::withMessages(['arranjo' => 'O espaçamento térmico deve superar o kerf antes de editar o pedido.']);
        }

        return [...$result, 'espacamento_minimo_mm' => $espacamento,
            'distancia_pecas_mm' => $espacamento,
            'margem_borda_mm' => max((float) ($result['margem_borda_mm'] ?? 0), (float) ($espessura?->espacamento_minimo_mm ?? 0)),
            'lead_in_mm' => (float) ($result['lead_in_mm'] ?? $espessura?->lead_in_mm ?? 2),
            'usar_tabs' => (bool) $pedido->usar_tabs,
            'tamanho_tab_mm' => (float) ($pedido->tamanho_tab_mm ?? 1)];
    }

    private function positions(Request $request, PedidoCorte $pedido, GeometriaNesting $geometria,
        PosicoesArranjo $validador): array
    {
        $data = $request->validate([
            'posicoes' => ['required', 'array', 'min:1', 'max:100'],
            'posicoes.*.instancia' => ['required', 'integer', 'min:0'],
            'posicoes.*.id' => ['required'],
            'posicoes.*.x' => ['required', 'numeric', 'min:0'],
            'posicoes.*.y' => ['required', 'numeric', 'min:0'],
            'posicoes.*.rotacao_graus' => ['required', 'numeric', 'between:-3600,3600'],
        ]);

        return $validador->validar($data['posicoes'], $pedido->resultado_nesting_json['pecas_posicionadas'] ?? [],
            $pedido->chapa, $geometria);
    }
}
