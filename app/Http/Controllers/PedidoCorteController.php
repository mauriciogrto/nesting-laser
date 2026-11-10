<?php

namespace App\Http\Controllers;

use App\Enums\StatusChapa;
use App\Enums\StatusPedido;
use App\Enums\StatusProcessamento;
use App\Jobs\GerarCncSaida;
use App\Jobs\RodarNesting;
use App\Models\Chapa;
use App\Models\HistoricoAcao;
use App\Models\Peca;
use App\Models\PedidoCorte;
use App\Services\AreaGeometria;
use App\Services\CalculadoraTempoCorte;
use App\Services\EstoqueDoArranjo;
use App\Services\MotorNesting;
use App\Services\PdfArranjo;
use App\Services\ProgramaCnc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PedidoCorteController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(StatusPedido::class)],
            'de' => ['nullable', 'date'],
            'ate' => ['nullable', 'date', 'after_or_equal:de'],
        ]);
        $query = PedidoCorte::query()
            ->select(['id', 'nome', 'status', 'chapa_id', 'retalho_chapa_id', 'created_at',
                'data_prevista', 'tempo_corte_real_seg', 'tempo_corte_estimado_seg',
                'custo_material', 'custo_maquina', 'custo_total'])
            ->with(['chapa.material', 'retalho', 'chapasInternas'])->latest();
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['de'])) {
            $query->whereDate('created_at', '>=', $filters['de']);
        }
        if (! empty($filters['ate'])) {
            $query->whereDate('created_at', '<=', $filters['ate']);
        }

        return view('pedidos.index', ['pedidos' => $query->paginate(20)->withQueryString(), 'filters' => $filters]);
    }

    public function create(): View
    {
        return view('pedidos.create', [
            'chapas' => Chapa::with(['material', 'espessura'])->where('status', StatusChapa::Disponivel->value)->where('quantidade_disponivel', '>', 0)->get(),
            'pecas' => Peca::where('status_processamento', StatusProcessamento::Processado->value)->orderBy('nome')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nome' => ['nullable', 'string', 'max:120'],
            'chapa_id' => ['required', 'integer', 'exists:chapas,id'],
            'pecas' => ['required', 'array', 'min:1'],
            'pecas.*' => ['required', 'integer', 'min:0', 'max:100'],
            'usar_tabs' => ['nullable', 'boolean'],
            'tamanho_tab_mm' => ['nullable', 'numeric', 'gt:0', 'max:100'],
        ]);
        $chapa = Chapa::findOrFail($data['chapa_id']);
        if ($chapa->status !== StatusChapa::Disponivel || $chapa->quantidade_disponivel < 1) {
            throw ValidationException::withMessages(['chapa_id' => 'Escolha uma chapa disponível.']);
        }
        $selected = array_filter($data['pecas'], fn ($quantity) => (int) $quantity > 0);
        if (! $selected || array_sum($selected) > 100) {
            throw ValidationException::withMessages(['pecas' => 'Escolha de 1 a 100 peças.']);
        }
        $validCount = Peca::whereKey(array_keys($selected))->where('status_processamento', StatusProcessamento::Processado->value)->count();
        if ($validCount !== count($selected)) {
            throw ValidationException::withMessages(['pecas' => 'Todas as peças devem estar processadas.']);
        }
        $pedido = DB::transaction(function () use ($chapa, $selected, $data): PedidoCorte {
            $pedido = PedidoCorte::create(['nome' => $data['nome'] ?? null,
                'chapa_id' => $chapa->id, 'status' => StatusPedido::Rascunho,
                'usar_tabs' => (bool) ($data['usar_tabs'] ?? false),
                'tamanho_tab_mm' => (float) ($data['tamanho_tab_mm'] ?? 1)]);
            $pedido->pecas()->sync(collect($selected)->mapWithKeys(fn ($quantity, $id) => [(int) $id => ['quantidade' => (int) $quantity]])->all());

            return $pedido;
        });

        return redirect()->route('pedidos.show', $pedido)->with('status', 'Pedido criado. Confirme para iniciar o nesting.');
    }

    public function show(PedidoCorte $pedido, AreaGeometria $area): View
    {
        $pedido->load(['chapa.material', 'chapa.espessura', 'retalho', 'pecas', 'chapasInternas.chapa.material', 'chapasInternas.chapa.espessura', 'chapasInternas.retalho']);
        $compor = function (?Chapa $chapa, ?array $resultado) use ($area): ?array {
            if (! $chapa || empty($resultado['pecas_posicionadas'])) {
                return null;
            }
            $chapaMm2 = $area->chapa($chapa->contorno_json ?? [], (float) $chapa->largura_mm, (float) $chapa->altura_mm);
            if ($chapaMm2 <= 0) {
                return null;
            }
            $pecasMm2 = $area->pecas($resultado['pecas_posicionadas']);
            $retalhoMm2 = ! empty($resultado['sobra_contorno']) ? $area->contorno($resultado['sobra_contorno']) : 0;

            return [
                'pecas_pct' => 100 * $pecasMm2 / $chapaMm2,
                'retalho_pct' => 100 * $retalhoMm2 / $chapaMm2,
                'vazios_pct' => 100 * max(0, $chapaMm2 - $pecasMm2 - $retalhoMm2) / $chapaMm2,
                'retalho_mm2' => $retalhoMm2,
            ];
        };
        $composicoes = [1 => $compor($pedido->chapa, $pedido->resultado_nesting_json)];
        foreach ($pedido->chapasInternas as $plano) {
            $composicoes[$plano->numero] = $compor($plano->chapa, $plano->resultado_nesting_json);
        }

        return view('pedidos.show', compact('pedido', 'composicoes'));
    }

    public function atualizarGestao(Request $request, PedidoCorte $pedido, EstoqueDoArranjo $estoque,
        ProgramaCnc $programa, MotorNesting $motor, CalculadoraTempoCorte $calculadora): RedirectResponse
    {
        $data = $request->validate([
            'nome' => ['nullable', 'string', 'max:120'],
            'data_prevista' => ['nullable', 'date'],
            'data_entrega' => ['nullable', 'date'],
            'tempo_corte_real_seg' => ['nullable', 'integer', 'min:1', 'max:999999999'],
            'usar_tabs' => ['nullable', 'boolean'],
            'tamanho_tab_mm' => ['nullable', 'numeric', 'gt:0', 'max:100'],
        ]);
        $export = DB::transaction(function () use ($pedido, $data, $estoque, $programa, $motor, $calculadora): array {
            $locked = PedidoCorte::with('chapa.espessura')->whereKey($pedido->id)->lockForUpdate()->firstOrFail();
            $usarTabs = array_key_exists('usar_tabs', $data) ? (bool) $data['usar_tabs'] : (bool) $locked->usar_tabs;
            $tamanhoTab = (float) ($data['tamanho_tab_mm'] ?? $locked->tamanho_tab_mm ?? 1);
            $mudouCorte = $usarTabs !== (bool) $locked->usar_tabs || abs($tamanhoTab - (float) $locked->tamanho_tab_mm) > 0.0005;
            if ($mudouCorte && in_array($locked->status, [StatusPedido::EmProducao, StatusPedido::Concluido], true)) {
                throw ValidationException::withMessages(['usar_tabs' => 'Não altere as microjunções após o início da produção.']);
            }
            $changes = $data;
            $files = [];
            $regenerate = false;
            $positions = $locked->resultado_nesting_json['pecas_posicionadas'] ?? [];
            if ($mudouCorte && $positions && $locked->chapa) {
                $espacamento = max((float) ($locked->resultado_nesting_json['espacamento_minimo_mm']
                    ?? $locked->resultado_nesting_json['distancia_pecas_mm'] ?? 0),
                    (float) $locked->chapa->espessura->espacamento_minimo_mm);
                $settings = [...$locked->resultado_nesting_json, 'usar_tabs' => $usarTabs,
                    'tamanho_tab_mm' => $tamanhoTab, 'espacamento_minimo_mm' => $espacamento,
                    'distancia_pecas_mm' => $espacamento,
                    'margem_borda_mm' => max((float) ($locked->resultado_nesting_json['margem_borda_mm'] ?? 0), (float) $locked->chapa->espessura->espacamento_minimo_mm),
                    'lead_in_mm' => (float) $locked->chapa->espessura->lead_in_mm];
                if ($espacamento <= (float) ($settings['kerf_mm'] ?? $locked->chapa->espessura->kerf_mm ?? 0)) {
                    throw ValidationException::withMessages(['usar_tabs' => 'O espaçamento térmico deve superar o kerf antes de editar o pedido.']);
                }
                try {
                    $motor->avaliar($locked->chapa, $positions, $settings);
                    $programa->preview($locked->chapa, $positions, $settings);
                } catch (\RuntimeException $error) {
                    throw ValidationException::withMessages(['usar_tabs' => $error->getMessage()]);
                }
                $changes['resultado_nesting_json'] = $settings;
                $changes['tempo_corte_estimado_seg'] = $calculadora->calcular($positions, $locked->chapa->espessura, $settings);
                $changes['cnc_saida'] = null;
                $changes['dxf_saida'] = null;
                $files = array_filter([$locked->cnc_saida, $locked->dxf_saida]);
                foreach ($locked->chapasInternas()->with('chapa.espessura')->get() as $plano) {
                    $childSettings = [...$plano->resultado_nesting_json,
                        'usar_tabs' => $usarTabs, 'tamanho_tab_mm' => $tamanhoTab];
                    $childPositions = $childSettings['pecas_posicionadas'] ?? [];
                    $motor->avaliar($plano->chapa, $childPositions, $childSettings);
                    try {
                        $programa->preview($plano->chapa, $childPositions, $childSettings);
                    } catch (\RuntimeException $error) {
                        throw ValidationException::withMessages(['usar_tabs' => $error->getMessage()]);
                    }
                    $childSeconds = $calculadora->calcular($childPositions, $plano->chapa->espessura, $childSettings);
                    $changes['tempo_corte_estimado_seg'] += $childSeconds;
                    $files = array_merge($files, array_filter([$plano->cnc_saida, $plano->dxf_saida]));
                    $plano->update(['resultado_nesting_json' => $childSettings,
                        'tempo_corte_estimado_seg' => $childSeconds,
                        'custo_maquina' => round((float) $plano->chapa->espessura->custo_hora_maquina * $childSeconds / 3600, 2),
                        'cnc_saida' => null, 'dxf_saida' => null]);
                }
                $regenerate = true;
            }
            $locked->update($changes);
            if ($locked->estoque_baixado_em && $locked->chapa) {
                $estoque->atualizarCustoTempo($locked, $locked->chapa);
            }

            return ['files' => $files, 'regenerate' => $regenerate];
        });
        if ($export['regenerate']) {
            Storage::disk('local')->delete($export['files']);
            GerarCncSaida::dispatch($pedido->id);
        }

        return redirect()->route('pedidos.show', $pedido)->with('status', 'Dados do pedido atualizados.');
    }

    public function confirmar(PedidoCorte $pedido): RedirectResponse
    {
        $shouldDispatch = DB::transaction(function () use ($pedido): bool {
            $locked = PedidoCorte::whereKey($pedido->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [StatusPedido::Rascunho, StatusPedido::Erro], true)) {
                return false;
            }
            if (! $locked->chapa_id || ! $locked->pecas()->exists()) {
                throw ValidationException::withMessages(['pedido' => 'Selecione uma chapa e pelo menos uma peça.']);
            }
            $locked->update([
                'status' => StatusPedido::AguardandoNesting,
                'resultado_nesting_json' => null,
                'tempo_corte_estimado_seg' => null,
                'sobra_aproveitavel_json' => null,
                'erro_mensagem' => null,
            ]);

            return true;
        });
        if ($shouldDispatch) {
            RodarNesting::dispatch($pedido->id);
        }

        return redirect()->route('pedidos.show', $pedido)->with('status', $shouldDispatch ? 'Nesting iniciado.' : 'Este pedido já foi confirmado.');
    }

    public function status(PedidoCorte $pedido): JsonResponse
    {
        return response()->json([
            'status' => $pedido->status->value,
            'erro' => $pedido->erro_mensagem,
            'area_utilizada_pct' => $pedido->resultado_nesting_json['area_utilizada_pct'] ?? null,
            'tempo_corte_estimado_seg' => $pedido->tempo_corte_estimado_seg,
        ]);
    }

    public function baixarCnc(PedidoCorte $pedido): StreamedResponse
    {
        abort_unless($pedido->cnc_saida && Storage::disk('local')->exists($pedido->cnc_saida), 404);
        $name = Str::slug($pedido->nome ?: 'pedido-'.$pedido->id) ?: 'pedido-'.$pedido->id;

        return Storage::disk('local')->download($pedido->cnc_saida, $name.'.cnc');
    }

    public function baixarDxf(PedidoCorte $pedido): StreamedResponse
    {
        abort_unless($pedido->dxf_saida && Storage::disk('local')->exists($pedido->dxf_saida), 404);
        $name = Str::slug($pedido->nome ?: 'pedido-'.$pedido->id) ?: 'pedido-'.$pedido->id;

        return Storage::disk('local')->download($pedido->dxf_saida, $name.'-tecnico.dxf');
    }

    public function baixarPdf(PedidoCorte $pedido, PdfArranjo $pdf): Response
    {
        abort_unless(count($pedido->resultado_nesting_json['pecas_posicionadas'] ?? []) > 0, 404);
        $name = Str::slug($pedido->nome ?: 'pedido-'.$pedido->id) ?: 'pedido-'.$pedido->id;

        return response($pdf->gerar($pedido), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'-arranjo.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function baixarCncChapa(PedidoCorte $pedido, int $numero): StreamedResponse
    {
        $plano = $pedido->chapasInternas()->where('numero', $numero)->firstOrFail();
        abort_unless($plano->cnc_saida && Storage::disk('local')->exists($plano->cnc_saida), 404);

        return Storage::disk('local')->download($plano->cnc_saida, 'pedido-'.$pedido->id.'-chapa-'.$numero.'.cnc');
    }

    public function baixarDxfChapa(PedidoCorte $pedido, int $numero): StreamedResponse
    {
        $plano = $pedido->chapasInternas()->where('numero', $numero)->firstOrFail();
        abort_unless($plano->dxf_saida && Storage::disk('local')->exists($plano->dxf_saida), 404);

        return Storage::disk('local')->download($plano->dxf_saida, 'pedido-'.$pedido->id.'-chapa-'.$numero.'-tecnico.dxf');
    }

    public function baixarPdfChapa(PedidoCorte $pedido, int $numero, PdfArranjo $pdf): Response
    {
        $plano = $pedido->chapasInternas()->with(['chapa.material', 'chapa.espessura'])->where('numero', $numero)->firstOrFail();

        return response($pdf->gerar($pedido, $plano), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="pedido-'.$pedido->id.'-chapa-'.$numero.'-arranjo.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function destroy(PedidoCorte $pedido, EstoqueDoArranjo $estoque): RedirectResponse
    {
        $files = DB::transaction(function () use ($pedido, $estoque): array {
            $locked = PedidoCorte::whereKey($pedido->id)->lockForUpdate()->firstOrFail();
            $estoque->reverterAoExcluir($locked);
            HistoricoAcao::create([
                'user_id' => auth()->id(), 'acao' => 'exclusao_pedido', 'entidade' => 'pedido_corte',
                'entidade_id' => $locked->id,
                'dados_alterados' => ['nome' => $locked->nome, 'status' => $locked->status->value,
                    'chapa_id' => $locked->chapa_id, 'retalho_chapa_id' => $locked->retalho_chapa_id,
                    'estoque_revertido' => (bool) $locked->estoque_baixado_em],
            ]);
            $files = array_filter([$locked->cnc_saida, $locked->dxf_saida]);
            foreach ($locked->chapasInternas as $plano) {
                $files = array_merge($files, array_filter([$plano->cnc_saida, $plano->dxf_saida]));
            }
            $locked->delete();

            return $files;
        });
        Storage::disk('local')->delete($files);

        return redirect()->route('pedidos.index')->with('status', 'Pedido excluído. O estoque foi ajustado quando necessário.');
    }
}
