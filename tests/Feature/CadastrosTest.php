<?php

namespace Tests\Feature;

use App\Enums\OrigemChapa;
use App\Enums\StatusChapa;
use App\Jobs\ExtrairGeometriaDxf;
use App\Models\Chapa;
use App\Models\EspessuraMaterial;
use App\Models\Material;
use App\Models\Peca;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CadastrosTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_stock_and_parts(): void
    {
        foreach (['/materiais', '/chapas', '/pecas'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
    }

    public function test_parts_list_shows_the_piece_name_and_format_without_file_name_or_processing_details(): void
    {
        $this->actingAs(User::factory()->create());
        Peca::factory()->create(['nome' => 'Suporte A',
            'arquivo_dxf_original' => 'pecas/arquivo-interno-123.dxf']);

        $this->get(route('pecas.index'))->assertOk()
            ->assertSee('Suporte A')->assertSee('Formato')->assertSee('DXF')
            ->assertDontSee('arquivo-interno-123.dxf')->assertDontSee('Processamento');
    }

    public function test_material_and_thickness_can_be_created(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post('/materiais', ['nome' => 'Titânio', 'densidade_kg_m3' => 4506])->assertRedirect('/materiais');
        $material = Material::where('nome', 'Titânio')->firstOrFail();
        $this->assertEquals(4506, (float) $material->densidade_kg_m3);
        $this->put("/materiais/{$material->id}", ['nome' => 'Titânio', 'densidade_kg_m3' => -1])
            ->assertSessionHasErrors('densidade_kg_m3');
        $this->post("/materiais/{$material->id}/espessuras", [
            'valor_mm' => 1.5, 'velocidade_corte_mm_min' => 900, 'tempo_perfuracao_seg' => 0.8,
            'kerf_mm' => 0.2, 'espacamento_minimo_mm' => 10, 'lead_in_mm' => 2,
        ])->assertRedirect();
        $this->assertDatabaseHas('espessuras_material', ['material_id' => $material->id, 'valor_mm' => 1.5]);
        $this->post("/materiais/{$material->id}/espessuras", [
            'valor_mm' => 3, 'velocidade_corte_mm_min' => 900, 'tempo_perfuracao_seg' => 0.8,
            'kerf_mm' => 1, 'espacamento_minimo_mm' => 0.5, 'lead_in_mm' => 2,
        ])->assertSessionHasErrors('espacamento_minimo_mm');
        $this->post("/materiais/{$material->id}/espessuras", [
            'valor_mm' => 3, 'velocidade_corte_mm_min' => 900, 'tempo_perfuracao_seg' => 0.8,
            'kerf_mm' => 0.2, 'espacamento_minimo_mm' => 0.2, 'lead_in_mm' => 2,
        ])->assertSessionHasErrors('espacamento_minimo_mm');
        $this->post("/materiais/{$material->id}/espessuras", [
            'valor_mm' => 3, 'velocidade_corte_mm_min' => 900, 'tempo_perfuracao_seg' => 0.8,
            'kerf_mm' => 2, 'espacamento_minimo_mm' => 2.1, 'lead_in_mm' => 2,
        ])->assertRedirect();
    }

    public function test_chapa_rejects_thickness_from_another_material(): void
    {
        $this->actingAs(User::factory()->create());
        $one = Material::factory()->create();
        $two = Material::factory()->create();
        $thickness = EspessuraMaterial::factory()->for($two)->create();

        $this->post('/chapas', $this->chapaData($one, $thickness))
            ->assertSessionHasErrors('espessura_id');
        $this->assertDatabaseCount('chapas', 0);
    }

    public function test_chapa_form_uses_only_material_thickness_and_dimensions(): void
    {
        $this->actingAs(User::factory()->create());
        $material = Material::factory()->create();
        $thickness = EspessuraMaterial::factory()->for($material)->create();
        $fields = [
            'material_id' => $material->id,
            'espessura_id' => $thickness->id,
            'largura_mm' => 1200,
            'altura_mm' => 3000,
        ];

        $this->get('/chapas/create')->assertOk()
            ->assertSee('name="largura_mm"', false)
            ->assertDontSee('name="quantidade_disponivel"', false)
            ->assertDontSee('name="custo_unitario"', false)
            ->assertDontSee('name="origem"', false);
        $this->post('/chapas', $fields)->assertRedirect('/chapas');

        $chapa = Chapa::sole();
        $this->assertSame(OrigemChapa::Nova, $chapa->origem);
        $this->assertSame(StatusChapa::Disponivel, $chapa->status);
        $this->assertSame(1, $chapa->quantidade_disponivel);
        $this->assertEquals(0, (float) $chapa->custo_unitario);

        $chapa->update(['quantidade_disponivel' => 3, 'custo_unitario' => 45]);
        $this->put("/chapas/{$chapa->id}", [...$fields, 'largura_mm' => 1500])->assertRedirect('/chapas');
        $this->assertEquals(1500, (float) $chapa->fresh()->largura_mm);
        $this->assertSame(3, $chapa->fresh()->quantidade_disponivel);
        $this->assertEquals(45, (float) $chapa->fresh()->custo_unitario);
    }

    public function test_chapa_filters_by_material_and_status(): void
    {
        $this->actingAs(User::factory()->create());
        $steel = Material::factory()->create(['nome' => 'Aço teste']);
        $aluminium = Material::factory()->create(['nome' => 'Alumínio teste']);
        $steelThickness = EspessuraMaterial::factory()->for($steel)->create();
        $aluminiumThickness = EspessuraMaterial::factory()->for($aluminium)->create();
        Chapa::factory()->create(array_merge($this->chapaData($steel, $steelThickness), ['status' => StatusChapa::Disponivel]));
        Chapa::factory()->create(array_merge($this->chapaData($aluminium, $aluminiumThickness), ['status' => StatusChapa::Reservada]));

        $this->get('/chapas?material_id='.$steel->id.'&status=disponivel')
            ->assertOk()->assertSee('<td>Aço teste</td>', false)->assertDontSee('<td>Alumínio teste</td>', false);
    }

    public function test_dxf_upload_is_private_and_invalid_extension_is_rejected(): void
    {
        Storage::fake('local');
        Bus::fake([ExtrairGeometriaDxf::class]);
        $this->actingAs(User::factory()->create());

        $this->post('/pecas', [
            'nome' => 'Suporte',
            'arquivo_dxf' => UploadedFile::fake()->createWithContent('suporte.dxf', "0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n"),
        ])->assertRedirect('/pecas');

        $peca = Peca::sole();
        Storage::disk('local')->assertExists($peca->arquivo_dxf_original);
        $this->assertNull($peca->contorno_json);
        Bus::assertDispatched(ExtrairGeometriaDxf::class);

        $this->post('/pecas', [
            'nome' => 'Inválido', 'arquivo_dxf' => UploadedFile::fake()->createWithContent('arquivo.txt', 'teste'),
        ])->assertSessionHasErrors('arquivo_dxf');
        $this->assertDatabaseCount('pecas', 1);
    }

    public function test_blank_part_name_uses_uploaded_dxf_filename(): void
    {
        Storage::fake('local');
        Bus::fake([ExtrairGeometriaDxf::class]);
        $this->actingAs(User::factory()->create());
        $fixture = file_get_contents(base_path('tests/Fixtures/retangulo.dxf'));

        $this->post('/pecas', [
            'nome' => '   ',
            'arquivo_dxf' => UploadedFile::fake()->createWithContent('07130_02.DXF', $fixture),
        ])->assertRedirect('/pecas');
        $peca = Peca::sole();
        $this->assertSame('07130_02', $peca->nome);

        $this->put("/pecas/{$peca->id}", ['nome' => ''])->assertRedirect('/pecas');
        $this->assertSame('07130_02', $peca->fresh()->nome);

        $this->put("/pecas/{$peca->id}", [
            'nome' => '',
            'arquivo_dxf' => UploadedFile::fake()->createWithContent('nova-peca.dxf', $fixture),
        ])->assertRedirect('/pecas');
        $this->assertSame('nova-peca', $peca->fresh()->nome);
    }

    public function test_dxf_upload_extracts_geometry_and_exposes_status(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $fixture = file_get_contents(base_path('tests/Fixtures/retangulo.dxf'));

        $this->post('/pecas', [
            'nome' => 'Retângulo',
            'arquivo_dxf' => UploadedFile::fake()->createWithContent('retangulo.dxf', $fixture),
        ])->assertRedirect('/pecas');

        $peca = Peca::sole();
        $this->assertSame('processado', $peca->status_processamento->value);
        $this->assertEquals(20, (float) $peca->largura_bbox_mm);
        $this->assertCount(1, $peca->contorno_json);
        $this->getJson('/pecas-status?ids[]='.$peca->id)->assertOk()
            ->assertJsonPath($peca->id.'.status', 'processado');
    }

    private function chapaData(Material $material, EspessuraMaterial $thickness): array
    {
        return [
            'material_id' => $material->id,
            'espessura_id' => $thickness->id,
            'largura_mm' => 1000,
            'altura_mm' => 2000,
            'origem' => OrigemChapa::Nova->value,
            'quantidade_disponivel' => 2,
            'status' => StatusChapa::Disponivel->value,
        ];
    }
}
