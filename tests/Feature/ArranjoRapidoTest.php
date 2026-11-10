<?php

namespace Tests\Feature;

use App\Enums\OrigemChapa;
use App\Enums\StatusChapa;
use App\Enums\StatusPedido;
use App\Enums\StatusProcessamento;
use App\Models\Chapa;
use App\Models\Peca;
use App\Models\PedidoCorte;
use App\Models\User;
use App\Services\ArranjoPreview;
use App\Services\GeometriaDxf;
use App\Services\ProgramaCnc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ArranjoRapidoTest extends TestCase
{
    use RefreshDatabase;

    public function test_optimizer_searches_processed_parts_without_rendering_the_entire_catalog(): void
    {
        $this->actingAs(User::factory()->operador()->create());
        $encontrada = Peca::factory()->create(['nome' => 'Suporte lateral',
            'status_processamento' => StatusProcessamento::Processado]);
        Peca::factory()->create(['nome' => 'Suporte não processado',
            'status_processamento' => StatusProcessamento::Pendente]);
        Peca::factory()->create(['nome' => 'Outra peça',
            'status_processamento' => StatusProcessamento::Processado]);

        $this->get(route('arranjos.create'))->assertOk()
            ->assertSee('Buscar por nome ou código')->assertSee('Nenhuma peça adicionada.')
            ->assertSee('Nome do arranjo')->assertDontSee('Ver exemplo no preview')
            ->assertDontSee('Suporte lateral')->assertDontSee('Outra peça');
        $this->getJson(route('arranjos.pecas.buscar', ['q' => 'Suporte']))
            ->assertOk()->assertJsonCount(1, 'pecas')
            ->assertJsonPath('pecas.0.id', $encontrada->id)
            ->assertJsonPath('pecas.0.nome', 'Suporte lateral');
        $this->getJson(route('arranjos.pecas.buscar', ['q' => (string) $encontrada->id]))
            ->assertOk()->assertJsonFragment(['id' => $encontrada->id]);
        $this->getJson(route('arranjos.pecas.buscar'))->assertUnprocessable();
    }

    public function test_invalid_dxf_reports_the_file_and_geometry_problem_instead_of_a_generic_preview_error(): void
    {
        Storage::fake('local');
        Cache::flush();
        $this->actingAs(User::factory()->operador()->create());
        $chapa = Chapa::factory()->create(['quantidade_disponivel' => 1]);
        $this->mock(GeometriaDxf::class, function ($mock) {
            $mock->shouldReceive('extrair')->once()->andThrow(new RuntimeException(
                'Entidades abertas ou desconectadas: nao formam contorno fechado',
            ));
        });

        $this->post(route('arranjos.store'), [
            'material_id' => $chapa->material_id, 'espessura_id' => $chapa->espessura_id,
            'arquivos' => [UploadedFile::fake()->createWithContent('aberta.dxf', 'invalid')],
            'quantidades' => [1],
        ])->assertRedirect()->assertSessionHasErrors('arquivos');

        $message = session('errors')->first('arquivos');
        $this->assertStringContainsString('aberta.dxf', $message);
        $this->assertStringContainsString('Entidades abertas ou desconectadas', $message);
        $this->assertStringContainsString('contorno fechado', $message);
        $this->assertDatabaseCount('pedidos_corte', 0);
        $this->assertSame([], Storage::disk('local')->directories('previews'));
    }

    public function test_engine_geometry_rejection_is_reported_instead_of_a_connection_error(): void
    {
        Storage::fake('local');
        Cache::flush();
        $this->actingAs(User::factory()->operador()->create());
        $chapa = Chapa::factory()->create(['quantidade_disponivel' => 1]);
        $chapa->espessura->update(['kerf_mm' => 0]);
        $this->fakeGeometry(1);
        Http::fake(['*/nesting' => Http::response([
            'erro' => 'Contorno da chapa fora das dimensões informadas.',
        ], 422)]);

        $this->post(route('arranjos.store'), [
            'material_id' => $chapa->material_id, 'espessura_id' => $chapa->espessura_id,
            'arquivos' => [UploadedFile::fake()->createWithContent('peca.dxf', 'fixture')],
            'quantidades' => [1],
        ])->assertSessionHasErrors(['arranjo' => 'O arranjo não pôde ser calculado: Contorno da chapa fora das dimensões informadas.']);
    }

    public function test_preview_reports_when_local_pc_worker_is_offline(): void
    {
        Storage::fake('local');
        Cache::flush();
        config(['nesting.pc_worker_enabled' => true]);
        $this->actingAs(User::factory()->operador()->create());
        $chapa = Chapa::factory()->create(['quantidade_disponivel' => 1]);
        $chapa->espessura->update(['kerf_mm' => 0]);
        $this->fakeGeometry(1);

        $this->post(route('arranjos.store'), [
            'material_id' => $chapa->material_id, 'espessura_id' => $chapa->espessura_id,
            'arquivos' => [UploadedFile::fake()->createWithContent('peca.dxf', 'fixture')],
            'quantidades' => [1],
        ])->assertSessionHasErrors(['arranjo' => 'O computador de otimização está desconectado. Ligue o processador local e tente novamente.']);

        $this->assertDatabaseCount('pedidos_corte', 0);
    }

    public function test_batch_preview_switches_sheet_without_reparsing_and_only_persists_after_acceptance(): void
    {
        Storage::fake('local');
        Cache::flush();
        config(['services.dxf.python' => (string) env('DXF_PYTHON_BIN', 'python3')]);
        $user = User::factory()->operador()->create();
        $this->actingAs($user);
        $nova = Chapa::factory()->create(['largura_mm' => 100, 'altura_mm' => 100, 'quantidade_disponivel' => 1]);
        $nova->espessura->update(['kerf_mm' => 0.2]);
        $retalho = Chapa::factory()->create([
            'material_id' => $nova->material_id, 'espessura_id' => $nova->espessura_id,
            'origem' => OrigemChapa::Retalho, 'chapa_pai_id' => $nova->id,
            'quantidade_disponivel' => 1,
            'largura_mm' => 30, 'altura_mm' => 20, 'contorno_json' => [[0, 0], [30, 0], [30, 20], [0, 20]],
        ]);
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls) {
            $calls++;
            $body = $request->data();
            $limit = $body['chapa']['largura_mm'] < 50 ? 1 : 100;
            $placed = [];
            $unplaced = [];
            $instance = 0;
            foreach ($body['pecas'] as $item) {
                for ($i = 0; $i < $item['quantidade']; $i++, $instance++) {
                    if ($instance < $limit) {
                        $placed[] = ['id' => $item['id'], 'instancia' => $instance, 'x' => $instance * 20,
                            'y' => 0, 'rotacao_graus' => 0, 'contornos' => $item['contornos']];
                    } else {
                        $unplaced[] = ['id' => $item['id'], 'instancia' => $instance];
                    }
                }
            }

            return Http::response(['pecas_posicionadas' => $placed, 'pecas_nao_posicionadas' => $unplaced,
                'area_utilizada_pct' => $limit * 2, 'sobra_contorno' => null]);
        });

        $fixture = file_get_contents(base_path('tests/Fixtures/retangulo.dxf'));
        $response = $this->post(route('arranjos.store'), [
            'material_id' => $nova->material_id, 'espessura_id' => $nova->espessura_id,
            'quantidades' => [2, 1, 1],
            'arquivos' => [
                UploadedFile::fake()->createWithContent('a.dxf', $fixture),
                UploadedFile::fake()->createWithContent('b.dxf', $fixture),
                UploadedFile::fake()->createWithContent('c.dxf', $fixture),
            ],
        ]);
        $response->assertRedirect();
        $token = basename(parse_url($response->headers->get('Location'), PHP_URL_PATH));
        $preview = app(ArranjoPreview::class)->obter($token, $user->id);
        $this->assertSame($retalho->id, $preview['planos'][0]['chapa_id']);
        $this->assertSame($nova->id, $preview['planos'][1]['chapa_id']);
        $this->assertCount(3, $preview['pecas']);
        $this->assertCount(2, $preview['planos']);
        $this->assertDatabaseCount('pecas', 0);
        $this->assertDatabaseCount('pedidos_corte', 0);
        $this->get(route('arranjos.show', $token))->assertOk()->assertSee('4 / 4');
        $this->getJson(route('arranjos.data', $token))->assertOk()->assertJsonCount(1, 'pecas_posicionadas')
            ->assertJsonPath('pecas_posicionadas.0.nome', 'a');
        $this->getJson(route('arranjos.data', ['token' => $token, 'plano' => 1]))->assertOk()->assertJsonCount(3, 'pecas_posicionadas');

        config(['services.dxf.python' => 'python-inexistente']);
        $this->post(route('arranjos.chapa', $token), ['chapa_id' => $nova->id])->assertRedirect(route('arranjos.show', $token));
        $this->assertSame(3, $calls);
        $this->assertDatabaseCount('pecas', 0);
        $this->get(route('arranjos.show', $token))->assertOk()->assertSee('4 / 4');
        $this->actingAs(User::factory()->operador()->create())->get(route('arranjos.show', $token))->assertNotFound();
        config(['services.dxf.python' => (string) env('DXF_PYTHON_BIN', 'python3')]);
        $this->actingAs($user)->post(route('arranjos.aceitar', $token))->assertRedirect();

        $pedido = PedidoCorte::sole();
        $this->assertSame(StatusPedido::Pronto, $pedido->status);
        $this->assertSame($nova->id, $pedido->chapa_id);
        $this->assertCount(4, $pedido->resultado_nesting_json['pecas_posicionadas']);
        $this->assertSame(3, $pedido->pecas()->count());
        $this->assertSame([2, 1, 1], $pedido->pecas()->orderBy('pecas.id')->get()->pluck('pivot.quantidade')->all());
        foreach (Peca::all() as $peca) {
            Storage::disk('local')->assertExists($peca->arquivo_dxf_original);
            $this->assertSame(20, $peca->contorno_json[0][1][0]);
        }
        $xs = array_column($pedido->resultado_nesting_json['pecas_posicionadas'][0]['contornos'][0], 0);
        $this->assertEqualsWithDelta(20.2, max($xs) - min($xs), 0.001);
        $this->assertFalse(Storage::disk('local')->exists('previews/'.$token));
        $this->get(route('arranjos.show', $token))->assertStatus(410);
        $this->get(route('pedidos.editor', $pedido))->assertOk();
    }

    public function test_expired_preview_files_are_removed(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $disk->put('previews/old/.active', 'old');
        $disk->put('previews/old/0.dxf', 'temporary');
        touch($disk->path('previews/old/.active'), now()->subMinutes(21)->timestamp);
        clearstatcache();
        $this->assertSame(1, app(ArranjoPreview::class)->limparExpirados());
        $this->assertFalse($disk->exists('previews/old/0.dxf'));
    }

    public function test_nine_drawings_with_quantities_create_one_order_with_two_sheets(): void
    {
        Storage::fake('local');
        Cache::flush();
        $this->actingAs(User::factory()->operador()->create());
        $chapa = Chapa::factory()->create(['quantidade_disponivel' => 2]);
        $chapa->espessura->update(['kerf_mm' => 0]);
        $this->fakeGeometry(9);
        $this->fakeNestingWithCapacity(5);

        $arquivos = [];
        for ($i = 0; $i < 9; $i++) {
            $arquivos[] = UploadedFile::fake()->createWithContent("desenho-{$i}.dxf", 'fixture');
        }
        $response = $this->post(route('arranjos.store'), [
            'material_id' => $chapa->material_id, 'espessura_id' => $chapa->espessura_id,
            'arquivos' => $arquivos, 'quantidades' => [2, 1, 1, 1, 1, 1, 1, 1, 1],
        ]);
        $response->assertRedirect();
        $token = basename(parse_url($response->headers->get('Location'), PHP_URL_PATH));
        $preview = app(ArranjoPreview::class)->obter($token, auth()->id());
        $this->assertCount(2, $preview['planos']);
        $this->assertCount(0, $preview['pecas_nao_posicionadas']);
        $this->assertDatabaseCount('pecas', 0);
        $this->assertDatabaseCount('pedidos_corte', 0);

        $this->post(route('arranjos.aceitar', $token))->assertRedirect();
        $this->assertDatabaseCount('pecas', 9);
        $this->assertDatabaseCount('pedidos_corte', 1);
        $this->assertDatabaseCount('pedido_chapas', 1);
        $pedido = PedidoCorte::with('pecas', 'chapasInternas')->sole();
        $this->assertSame(5, count($pedido->resultado_nesting_json['pecas_posicionadas']));
        $this->assertSame(5, count($pedido->chapasInternas->first()->resultado_nesting_json['pecas_posicionadas']));
        $this->assertSame(10, $pedido->pecas->sum('pivot.quantidade'));
        $this->assertSame(2, $pedido->pecas->firstWhere('nome', 'desenho-0')?->pivot->quantidade);
        $this->assertSame(0, $chapa->refresh()->quantidade_disponivel);
        $this->assertSame(0, app(ArranjoPreview::class)->disponiveis($chapa->material_id, $chapa->espessura_id)->count());
        $this->get(route('arranjos.create'))->assertOk()->assertViewHas('chapas', fn ($chapas) => $chapas->isEmpty());
        foreach (Peca::all() as $peca) {
            Storage::disk('local')->assertExists($peca->arquivo_dxf_original);
        }
        $this->get(route('pedidos.show', $pedido))->assertOk()->assertSee('Chapa 2 do mesmo pedido');
        $this->delete(route('pedidos.destroy', $pedido))->assertRedirect(route('pedidos.index'));
        $this->assertDatabaseCount('pedidos_corte', 0);
        $this->assertDatabaseCount('pedido_chapas', 0);
        $this->assertSame(2, $chapa->refresh()->quantidade_disponivel);
    }

    public function test_sixty_requested_pieces_wait_for_enough_sheets_before_acceptance(): void
    {
        Storage::fake('local');
        Cache::flush();
        $this->actingAs(User::factory()->operador()->create());
        $chapa = Chapa::factory()->create(['quantidade_disponivel' => 1]);
        $chapa->espessura->update(['kerf_mm' => 0]);
        $this->fakeGeometry(3);
        $this->fakeNestingWithCapacity(20);

        $arquivos = [];
        for ($i = 0; $i < 3; $i++) {
            $arquivos[] = UploadedFile::fake()->createWithContent("lote-{$i}.dxf", 'fixture');
        }
        $response = $this->post(route('arranjos.store'), [
            'material_id' => $chapa->material_id, 'espessura_id' => $chapa->espessura_id,
            'arquivos' => $arquivos, 'quantidades' => [20, 20, 20],
        ]);
        $response->assertRedirect();
        $token = basename(parse_url($response->headers->get('Location'), PHP_URL_PATH));
        $preview = app(ArranjoPreview::class)->obter($token, auth()->id());
        $this->assertCount(1, $preview['planos']);
        $this->assertCount(40, $preview['pecas_nao_posicionadas']);
        $this->post(route('arranjos.aceitar', $token))->assertSessionHasErrors('arranjo');
        $this->assertDatabaseCount('pedidos_corte', 0);

        $outra = Chapa::factory()->create([
            'material_id' => $chapa->material_id, 'espessura_id' => $chapa->espessura_id,
            'quantidade_disponivel' => 2,
        ]);
        $this->post(route('arranjos.chapa', $token), ['chapa_id' => $outra->id])->assertRedirect();
        $preview = app(ArranjoPreview::class)->obter($token, auth()->id());
        $this->assertCount(3, $preview['planos']);
        $this->assertCount(0, $preview['pecas_nao_posicionadas']);
        $this->post(route('arranjos.aceitar', $token))->assertRedirect();
        $this->assertDatabaseCount('pedidos_corte', 1);
        $this->assertDatabaseCount('pedido_chapas', 2);
        $this->assertSame(60, PedidoCorte::with('pecas')->sole()->pecas->sum('pivot.quantidade'));
    }

    public function test_uploaded_and_catalog_parts_can_be_repositioned_then_exported_with_stock_and_costs(): void
    {
        Storage::fake('local');
        Cache::flush();
        $this->actingAs(User::factory()->operador()->create());
        $chapa = Chapa::factory()->create(['quantidade_disponivel' => 1, 'largura_mm' => 100,
            'altura_mm' => 100, 'custo_unitario' => 100]);
        $chapa->espessura->update(['kerf_mm' => 0, 'custo_hora_maquina' => 3600]);
        $catalogada = Peca::factory()->create(['status_processamento' => StatusProcessamento::Processado,
            'contorno_json' => [[[0, 0], [10, 0], [10, 10], [0, 10]]],
            'largura_bbox_mm' => 10, 'altura_bbox_mm' => 10]);
        $this->fakeGeometry(1);
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/avaliar')) {
                return Http::response([
                    'area_utilizada_pct' => 2,
                    'sobra_contorno' => [[20, 0], [100, 0], [100, 100], [20, 100]],
                ]);
            }
            $items = $request->data()['pecas'];

            return Http::response(['pecas_posicionadas' => array_map(fn ($item, $index) => [
                'id' => $item['id'], 'instancia' => $index, 'x' => $index * 10, 'y' => 0,
                'rotacao_graus' => 0, 'contornos' => $item['contornos'],
            ], $items, array_keys($items)), 'pecas_nao_posicionadas' => [],
                'area_utilizada_pct' => 2, 'sobra_contorno' => [[20, 0], [100, 0], [100, 100], [20, 100]]]);
        });
        $response = $this->post(route('arranjos.store'), [
            'material_id' => $chapa->material_id, 'espessura_id' => $chapa->espessura_id,
            'arquivos' => [UploadedFile::fake()->createWithContent('nova.dxf', 'fixture')],
            'quantidades' => [1], 'pecas_existentes' => [$catalogada->id => 1],
        ]);
        $response->assertRedirect();
        $token = basename(parse_url($response->headers->get('Location'), PHP_URL_PATH));
        $preview = app(ArranjoPreview::class)->obter($token, auth()->id());
        $this->assertDatabaseCount('pecas', 1);
        $this->assertCount(2, $preview['pecas']);
        $positions = array_map(fn ($item) => ['id' => $item['id'], 'instancia' => $item['instancia'],
            'x' => $item['instancia'] === 0 ? 30 : 10, 'y' => 0, 'rotacao_graus' => 0],
            $preview['planos'][0]['resultado']['pecas_posicionadas']);
        $this->postJson(route('arranjos.posicoes', $token), ['plano' => 0, 'posicoes' => $positions])
            ->assertOk()->assertJsonPath('area_utilizada_pct', 2);
        $this->assertEquals(30, app(ArranjoPreview::class)->obter($token, auth()->id())['planos'][0]['resultado']['pecas_posicionadas'][0]['x']);
        $this->post(route('arranjos.aceitar', $token))->assertRedirect();
        $pedido = PedidoCorte::with('pecas')->sole();
        $this->assertSame(StatusPedido::Pronto, $pedido->status);
        $this->assertNotNull($pedido->estoque_baixado_em);
        $this->assertSame(0, $chapa->refresh()->quantidade_disponivel);
        $this->assertSame(StatusChapa::Baixada, $chapa->status);
        $this->assertDatabaseCount('pecas', 2);
        $this->assertTrue($pedido->pecas->contains('id', $catalogada->id));
        $this->assertNotNull($pedido->retalho_chapa_id);
        $this->assertSame('20.00', $pedido->custo_material);
        $this->assertGreaterThan(0, (float) $pedido->custo_maquina);
        $this->assertEqualsWithDelta((float) $pedido->custo_material + (float) $pedido->custo_maquina,
            (float) $pedido->custo_total, 0.01);
    }

    public function test_real_dxf_upload_exports_downloadable_cnc_and_technical_dxf(): void
    {
        Storage::fake('local');
        Cache::flush();
        config(['services.dxf.python' => (string) env('DXF_PYTHON_BIN', 'python3')]);
        $this->actingAs(User::factory()->operador()->create());
        $chapa = Chapa::factory()->create(['largura_mm' => 100, 'altura_mm' => 100,
            'quantidade_disponivel' => 1]);
        $chapa->espessura->update(['kerf_mm' => 0.1]);
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/avaliar')) {
                return Http::response(['area_utilizada_pct' => 2,
                    'sobra_contorno' => [[30, 0], [100, 0], [100, 100], [30, 100]]]);
            }
            $item = $request->data()['pecas'][0];

            return Http::response(['pecas_posicionadas' => [[
                'id' => $item['id'], 'instancia' => 0, 'x' => 5, 'y' => 5,
                'rotacao_graus' => 0, 'contornos' => $item['contornos'],
            ]], 'pecas_nao_posicionadas' => [], 'area_utilizada_pct' => 2,
                'sobra_contorno' => [[30, 0], [100, 0], [100, 100], [30, 100]]]);
        });

        $response = $this->post(route('arranjos.store'), [
            'nome' => 'teste',
            'material_id' => $chapa->material_id, 'espessura_id' => $chapa->espessura_id,
            'arquivos' => [UploadedFile::fake()->createWithContent('retangulo.dxf',
                file_get_contents(base_path('tests/Fixtures/retangulo.dxf')))],
            'quantidades' => [1],
        ]);
        $response->assertRedirect();
        $token = basename(parse_url($response->headers->get('Location'), PHP_URL_PATH));
        $this->post(route('arranjos.aceitar', $token))->assertRedirect();

        $pedido = PedidoCorte::sole();
        $this->assertSame('teste', $pedido->nome);
        $this->assertNotNull($pedido->cnc_saida, $pedido->erro_mensagem ?? 'CNC não gerado');
        Storage::disk('local')->assertExists($pedido->cnc_saida);
        $this->assertNotNull($pedido->dxf_saida, $pedido->erro_mensagem ?? 'DXF técnico não gerado');
        Storage::disk('local')->assertExists($pedido->dxf_saida);
        $this->get(route('pedidos.cnc', $pedido))->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=teste.cnc');
        $this->get(route('pedidos.dxf', $pedido))->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=teste-tecnico.dxf');
        $oldCnc = $pedido->cnc_saida;
        $oldDxf = $pedido->dxf_saida;
        $this->patch(route('pedidos.gestao', $pedido), ['usar_tabs' => 1,
            'tamanho_tab_mm' => 1])->assertRedirect();
        $pedido->refresh();
        $this->assertTrue($pedido->usar_tabs);
        $this->assertNotSame($oldCnc, $pedido->cnc_saida);
        $this->assertNotSame($oldDxf, $pedido->dxf_saida);
        Storage::disk('local')->assertMissing([$oldCnc, $oldDxf]);
        Storage::disk('local')->assertExists($pedido->cnc_saida);
        Storage::disk('local')->assertExists($pedido->dxf_saida);
        $this->assertSame(0, $chapa->refresh()->quantidade_disponivel);
        $this->assertNotNull($pedido->retalho_chapa_id);
    }

    public function test_preview_reapplies_kerf_and_safe_area_when_reorganized(): void
    {
        Storage::fake('local');
        Cache::flush();
        config(['services.dxf.python' => (string) env('DXF_PYTHON_BIN', 'python3')]);
        $user = User::factory()->operador()->create();
        $this->actingAs($user);
        $chapa = Chapa::factory()->create(['largura_mm' => 100, 'altura_mm' => 100,
            'quantidade_disponivel' => 1]);
        $chapa->espessura->update(['kerf_mm' => 0.1]);
        $requests = [];
        Http::fake(function (Request $request) use (&$requests) {
            $requests[] = $request->data();
            $part = $request->data()['pecas'][0];

            return Http::response(['pecas_posicionadas' => [[
                'id' => $part['id'], 'instancia' => 0, 'x' => 10, 'y' => 10,
                'rotacao_graus' => 0, 'contornos' => $part['contornos'],
            ]], 'pecas_nao_posicionadas' => [], 'area_utilizada_pct' => 2,
                'sobra_contorno' => null]);
        });
        foreach ([['distancia_pecas_mm' => 9, 'margem_borda_mm' => 15, 'erro' => 'distancia_pecas_mm'],
            ['distancia_pecas_mm' => 12, 'margem_borda_mm' => 9, 'erro' => 'margem_borda_mm']] as $inseguro) {
            $this->post(route('arranjos.store'), [
                'material_id' => $chapa->material_id, 'espessura_id' => $chapa->espessura_id,
                'chapa_id' => $chapa->id, 'kerf_mm' => 0.3,
                'distancia_pecas_mm' => $inseguro['distancia_pecas_mm'],
                'margem_borda_mm' => $inseguro['margem_borda_mm'],
                'arquivos' => [UploadedFile::fake()->createWithContent('retangulo.dxf',
                    file_get_contents(base_path('tests/Fixtures/retangulo.dxf')))],
                'quantidades' => [1],
            ])->assertSessionHasErrors($inseguro['erro']);
        }
        $response = $this->post(route('arranjos.store'), [
            'material_id' => $chapa->material_id, 'espessura_id' => $chapa->espessura_id,
            'chapa_id' => $chapa->id, 'kerf_mm' => 0.3,
            'margem_borda_mm' => 15, 'distancia_pecas_mm' => 12, 'lead_in_mm' => 3,
            'arquivos' => [UploadedFile::fake()->createWithContent('retangulo.dxf',
                file_get_contents(base_path('tests/Fixtures/retangulo.dxf')))],
            'quantidades' => [1],
        ]);
        $response->assertRedirect();
        $token = basename(parse_url($response->headers->get('Location'), PHP_URL_PATH));
        $preview = app(ArranjoPreview::class)->obter($token, $user->id);
        $this->assertSame(0.3, $preview['kerf_mm']);
        $this->assertSame(15.0, $requests[0]['margem_borda_mm']);
        $this->assertSame(12.0, $requests[0]['distancia_pecas_mm']);
        $this->assertSame(3.0, $preview['lead_in_mm']);
        $this->assertSame(20.3, round(max(array_column($preview['pecas'][0]['contornos_compensados'][0], 0))
            - min(array_column($preview['pecas'][0]['contornos_compensados'][0], 0)), 1));

        $this->post(route('arranjos.chapa', $token), ['chapa_id' => $chapa->id,
            'kerf_mm' => 0.5, 'margem_borda_mm' => 16, 'distancia_pecas_mm' => 14,
            'lead_in_mm' => 4])->assertRedirect();
        $preview = app(ArranjoPreview::class)->obter($token, $user->id);
        $this->assertSame(0.5, $preview['kerf_mm']);
        $this->assertSame(16.0, $requests[1]['margem_borda_mm']);
        $this->assertSame(14.0, $requests[1]['distancia_pecas_mm']);
        $this->assertSame(4.0, $preview['lead_in_mm']);
        $this->assertSame(20.5, round(max(array_column($preview['pecas'][0]['contornos_compensados'][0], 0))
            - min(array_column($preview['pecas'][0]['contornos_compensados'][0], 0)), 1));
        $this->post(route('arranjos.aceitar', $token))->assertRedirect();
        $pedido = PedidoCorte::sole();
        $this->assertSame(0.5, $pedido->resultado_nesting_json['kerf_mm']);
        $this->assertEquals(16.0, $pedido->resultado_nesting_json['margem_borda_mm']);
        $this->assertEquals(14.0, $pedido->resultado_nesting_json['distancia_pecas_mm']);
        $this->assertEquals(4.0, $pedido->resultado_nesting_json['lead_in_mm']);
        Storage::disk('local')->assertExists($pedido->cnc_saida);
    }

    public function test_preview_keeps_outlines_visible_when_a_safe_cnc_lead_is_unavailable(): void
    {
        Storage::fake('local');
        Cache::flush();
        $this->actingAs(User::factory()->operador()->create());
        $chapa = Chapa::factory()->create(['quantidade_disponivel' => 1]);
        $chapa->espessura->update(['kerf_mm' => 0]);
        $this->fakeGeometry(1);
        $this->fakeNestingWithCapacity(1);
        $response = $this->post(route('arranjos.store'), [
            'material_id' => $chapa->material_id, 'espessura_id' => $chapa->espessura_id,
            'arquivos' => [UploadedFile::fake()->createWithContent('peca.dxf', 'fixture')],
            'quantidades' => [1],
        ]);
        $token = basename(parse_url($response->headers->get('Location'), PHP_URL_PATH));
        $this->mock(ProgramaCnc::class, function ($mock) {
            $mock->shouldReceive('preview')->once()->andThrow(new RuntimeException('Entrada indisponível'));
        });
        $this->getJson(route('arranjos.data', $token))->assertOk()
            ->assertJsonCount(1, 'pecas_posicionadas')
            ->assertJsonCount(0, 'trajetos_cnc')
            ->assertJsonPath('erro_cnc', 'Não há trajetória de entrada segura para o CNC: Entrada indisponível');
    }

    public function test_ready_order_can_add_a_dxf_and_reoptimize_across_internal_sheets_without_creating_another_order(): void
    {
        Storage::fake('local');
        Cache::flush();
        config(['services.dxf.python' => (string) env('DXF_PYTHON_BIN', 'python3')]);
        $user = User::factory()->operador()->create();
        $this->actingAs($user);
        $chapa = Chapa::factory()->create(['largura_mm' => 100, 'altura_mm' => 100,
            'quantidade_disponivel' => 2]);
        $chapa->espessura->update(['kerf_mm' => 0.1]);
        Http::fake(function (Request $request) {
            $item = $request->data()['pecas'][0];
            $unplaced = [];
            $instance = 0;
            foreach ($request->data()['pecas'] as $part) {
                for ($i = 0; $i < $part['quantidade']; $i++, $instance++) {
                    if ($instance > 0) {
                        $unplaced[] = ['id' => $part['id'], 'instancia' => $instance];
                    }
                }
            }

            return Http::response(['pecas_posicionadas' => [[
                'id' => $item['id'], 'instancia' => 0, 'x' => 20, 'y' => 20,
                'rotacao_graus' => 0, 'contornos' => $item['contornos'],
            ]], 'pecas_nao_posicionadas' => $unplaced, 'area_utilizada_pct' => 5, 'sobra_contorno' => null]);
        });
        $fixture = file_get_contents(base_path('tests/Fixtures/retangulo.dxf'));
        $novo = $this->post(route('arranjos.store'), [
            'material_id' => $chapa->material_id, 'espessura_id' => $chapa->espessura_id,
            'arquivos' => [UploadedFile::fake()->createWithContent('original.dxf', $fixture)],
            'quantidades' => [1],
        ]);
        $novo->assertRedirect();
        $this->post(route('arranjos.aceitar', basename(parse_url($novo->headers->get('Location'), PHP_URL_PATH))))->assertRedirect();
        $pedido = PedidoCorte::sole();
        $cncAntigo = $pedido->cnc_saida;
        $this->assertNotNull($cncAntigo, $pedido->erro_mensagem ?? 'CNC inicial não foi gerado');
        $this->get(route('pedidos.acrescentar', $pedido))->assertOk()->assertSee('original')
            ->assertSee('Pré-visualizar novo arranjo');

        $revisao = $this->post(route('arranjos.store'), [
            'pedido_id' => $pedido->id,
            'material_id' => $chapa->material_id, 'espessura_id' => $chapa->espessura_id,
            'arquivos' => [UploadedFile::fake()->createWithContent('nova.dxf', $fixture)],
            'quantidades' => [1],
        ]);
        $revisao->assertRedirect();
        $token = basename(parse_url($revisao->headers->get('Location'), PHP_URL_PATH));
        $preview = app(ArranjoPreview::class)->obter($token, $user->id);
        $this->assertSame($pedido->id, $preview['pedido_id']);
        $this->assertCount(2, $preview['planos']);
        $this->assertCount(0, $preview['pecas_nao_posicionadas']);
        $this->assertDatabaseCount('pedidos_corte', 1);
        $this->assertSame(1, $chapa->refresh()->quantidade_disponivel);
        $this->get(route('arranjos.show', $token))->assertOk()->assertSee('Confirmar revisão e gerar CNC');

        $this->post(route('arranjos.aceitar', $token))->assertRedirect(route('pedidos.show', $pedido));
        $pedido->refresh();
        $this->assertDatabaseCount('pedidos_corte', 1);
        $this->assertSame(2, $pedido->pecas()->sum('quantidade'));
        $this->assertSame(1, $pedido->chapasInternas()->count());
        $this->assertSame(0, $chapa->refresh()->quantidade_disponivel);
        $this->assertNotSame($cncAntigo, $pedido->cnc_saida);
        Storage::disk('local')->assertMissing($cncAntigo);
        Storage::disk('local')->assertExists($pedido->cnc_saida);
        Storage::disk('local')->assertExists($pedido->chapasInternas->first()->cnc_saida);
        $this->get(route('pedidos.pdf', $pedido))->assertOk();

        $manter = $pedido->pecas->first();
        $edicao = $this->post(route('arranjos.store'), [
            'pedido_id' => $pedido->id, 'substituir_pecas' => 1,
            'material_id' => $chapa->material_id, 'espessura_id' => $chapa->espessura_id,
            'pecas_existentes' => [$manter->id => 1],
        ]);
        $edicao->assertRedirect();
        $tokenEdicao = basename(parse_url($edicao->headers->get('Location'), PHP_URL_PATH));
        $this->assertCount(1, app(ArranjoPreview::class)->obter($tokenEdicao, $user->id)['planos']);
        $this->assertSame(2, $pedido->refresh()->pecas()->sum('quantidade'));
        $this->post(route('arranjos.aceitar', $tokenEdicao))->assertRedirect(route('pedidos.show', $pedido));
        $this->assertSame(1, $pedido->refresh()->pecas()->sum('quantidade'));
        $this->assertSame(0, $pedido->chapasInternas()->count());
        $this->assertSame(1, $chapa->refresh()->quantidade_disponivel);
    }

    public function test_revision_uses_edited_quantities_removes_a_piece_and_adds_another_only_in_the_preview(): void
    {
        Storage::fake('local');
        Cache::flush();
        $user = User::factory()->operador()->create();
        $this->actingAs($user);
        $chapa = Chapa::factory()->create(['largura_mm' => 100, 'altura_mm' => 100,
            'quantidade_disponivel' => 0, 'status' => StatusChapa::Baixada]);
        $chapa->espessura->update(['kerf_mm' => 0]);
        $geometry = [[[0, 0], [10, 0], [10, 10], [0, 10]]];
        $parts = collect(['Manter', 'Remover', 'Adicionar'])->map(fn ($name) => Peca::factory()->create([
            'nome' => $name, 'status_processamento' => StatusProcessamento::Processado,
            'contorno_json' => $geometry, 'largura_bbox_mm' => 10, 'altura_bbox_mm' => 10,
        ]));
        $pedido = PedidoCorte::factory()->create(['chapa_id' => $chapa->id,
            'status' => StatusPedido::Pronto, 'estoque_baixado_em' => now()]);
        $pedido->pecas()->attach($parts[0]->id, ['quantidade' => 3]);
        $pedido->pecas()->attach($parts[1]->id, ['quantidade' => 2]);
        $this->get(route('pedidos.acrescentar', $pedido))->assertOk()
            ->assertSee('Editar arranjo')->assertSee('Adicionar nova peça')
            ->assertSee('Manter')->assertSee('Remover')->assertSee('Distância segura entre peças')
            ->assertDontSee('Usar microjunções (tabs)');

        $this->post(route('arranjos.store'), [
            'pedido_id' => $pedido->id, 'substituir_pecas' => 1,
            'material_id' => $chapa->material_id, 'espessura_id' => $chapa->espessura_id,
            'pecas_existentes' => [],
        ])->assertSessionHasErrors('pecas_existentes');

        $this->fakeNestingWithCapacity(3);
        $response = $this->post(route('arranjos.store'), [
            'pedido_id' => $pedido->id, 'substituir_pecas' => 1,
            'material_id' => $chapa->material_id, 'espessura_id' => $chapa->espessura_id,
            'lead_in_mm' => 3.4, 'usar_tabs' => 0,
            'pecas_existentes' => [$parts[0]->id => 2, $parts[2]->id => 1],
        ]);
        $response->assertRedirect();
        $token = basename(parse_url($response->headers->get('Location'), PHP_URL_PATH));
        $preview = app(ArranjoPreview::class)->obter($token, $user->id);
        $this->assertSame([$parts[0]->id => 2, $parts[2]->id => 1],
            collect($preview['pecas'])->mapWithKeys(fn ($part) => [$part['peca_id'] => $part['quantidade']])->all());
        $this->assertSame(3.4, $preview['lead_in_mm']);
        $this->assertCount(3, $preview['planos'][0]['resultado']['pecas_posicionadas']);
        $this->assertSame(5, $pedido->refresh()->pecas()->sum('quantidade'));
        $this->assertDatabaseCount('pedidos_corte', 1);
    }

    public function test_revision_rejects_orders_after_production_starts(): void
    {
        $this->actingAs(User::factory()->operador()->create());
        $pedido = PedidoCorte::factory()->create(['status' => StatusPedido::EmProducao,
            'estoque_baixado_em' => now()]);
        $this->get(route('pedidos.acrescentar', $pedido))->assertRedirect()->assertSessionHasErrors('pedido');
    }

    public function test_revision_does_not_change_the_order_when_its_old_remnant_has_been_used(): void
    {
        Storage::fake('local');
        Cache::flush();
        $user = User::factory()->operador()->create();
        $this->actingAs($user);
        $chapa = Chapa::factory()->create(['largura_mm' => 100, 'altura_mm' => 100,
            'quantidade_disponivel' => 0, 'status' => StatusChapa::Baixada]);
        $chapa->espessura->update(['kerf_mm' => 0]);
        $retalho = Chapa::factory()->create(['material_id' => $chapa->material_id,
            'espessura_id' => $chapa->espessura_id, 'origem' => OrigemChapa::Retalho,
            'chapa_pai_id' => $chapa->id, 'quantidade_disponivel' => 0,
            'status' => StatusChapa::Baixada]);
        $peca = Peca::factory()->create(['status_processamento' => StatusProcessamento::Processado,
            'contorno_json' => [[[0, 0], [10, 0], [10, 10], [0, 10]]],
            'largura_bbox_mm' => 10, 'altura_bbox_mm' => 10]);
        $pedido = PedidoCorte::factory()->create(['chapa_id' => $chapa->id,
            'retalho_chapa_id' => $retalho->id, 'status' => StatusPedido::Pronto,
            'estoque_baixado_em' => now(), 'exportado_em' => now()]);
        $pedido->pecas()->attach($peca->id, ['quantidade' => 1]);
        PedidoCorte::factory()->create(['chapa_id' => $retalho->id, 'status' => StatusPedido::Pronto,
            'estoque_baixado_em' => now()]);
        Http::fake(function (Request $request) {
            $item = $request->data()['pecas'][0];

            return Http::response(['pecas_posicionadas' => [
                ['id' => $item['id'], 'instancia' => 0, 'x' => 20, 'y' => 20,
                    'rotacao_graus' => 0, 'contornos' => $item['contornos']],
                ['id' => $item['id'], 'instancia' => 1, 'x' => 50, 'y' => 20,
                    'rotacao_graus' => 0, 'contornos' => $item['contornos']],
            ], 'pecas_nao_posicionadas' => [], 'area_utilizada_pct' => 8, 'sobra_contorno' => null]);
        });
        $response = $this->post(route('arranjos.store'), ['pedido_id' => $pedido->id,
            'material_id' => $chapa->material_id, 'espessura_id' => $chapa->espessura_id,
            'pecas_existentes' => [$peca->id => 1]]);
        $response->assertRedirect();
        $token = basename(parse_url($response->headers->get('Location'), PHP_URL_PATH));
        $this->mock(ProgramaCnc::class, function ($mock) {
            $mock->shouldReceive('preview')->once()->andReturn([]);
        });
        $this->post(route('arranjos.aceitar', $token))->assertSessionHasErrors('pedido');
        $this->assertSame(1, (int) $pedido->pecas()->first()->pivot->quantidade);
        $this->assertSame(0, $chapa->refresh()->quantidade_disponivel);
        $this->assertSame($retalho->id, $pedido->refresh()->retalho_chapa_id);
        $this->assertSame(StatusPedido::Pronto, $pedido->status);
    }

    private function fakeGeometry(int $calls): void
    {
        $this->mock(GeometriaDxf::class, function ($mock) use ($calls) {
            $mock->shouldReceive('extrair')->times($calls)->andReturn([
                'contornos' => [[[0, 0], [10, 0], [10, 10], [0, 10]]],
                'bounding_box' => ['largura_mm' => 10, 'altura_mm' => 10],
            ]);
        });
    }

    private function fakeNestingWithCapacity(int $capacity): void
    {
        Http::fake(function (Request $request) use ($capacity) {
            $placed = [];
            $unplaced = [];
            $instance = 0;
            foreach ($request->data()['pecas'] as $item) {
                for ($i = 0; $i < $item['quantidade']; $i++, $instance++) {
                    if ($instance < $capacity) {
                        $placed[] = ['id' => $item['id'], 'instancia' => $instance,
                            'x' => $instance * 10, 'y' => 0, 'rotacao_graus' => 0, 'contornos' => $item['contornos']];
                    } else {
                        $unplaced[] = ['id' => $item['id'], 'instancia' => $instance];
                    }
                }
            }

            return Http::response(['pecas_posicionadas' => $placed, 'pecas_nao_posicionadas' => $unplaced,
                'area_utilizada_pct' => 5, 'sobra_contorno' => null]);
        });
    }
}
