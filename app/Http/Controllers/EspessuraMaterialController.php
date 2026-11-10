<?php

namespace App\Http\Controllers;

use App\Models\EspessuraMaterial;
use App\Models\Material;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class EspessuraMaterialController extends Controller
{
    public function index(Material $material): View
    {
        return view('espessuras.index', ['material' => $material, 'espessuras' => $material->espessuras()->orderBy('valor_mm')->get()]);
    }

    public function create(Material $material): View
    {
        return view('espessuras.form', ['material' => $material, 'espessura' => new EspessuraMaterial]);
    }

    public function store(Request $request, Material $material): RedirectResponse
    {
        $data = $this->validated($request, $material);
        $material->espessuras()->create($data);

        return redirect()->route('materiais.espessuras.index', $material)->with('status', 'Espessura cadastrada.');
    }

    public function edit(Material $material, EspessuraMaterial $espessura): View
    {
        $this->assertOwnership($material, $espessura);

        return view('espessuras.form', compact('material', 'espessura'));
    }

    public function update(Request $request, Material $material, EspessuraMaterial $espessura): RedirectResponse
    {
        $this->assertOwnership($material, $espessura);
        $espessura->update($this->validated($request, $material, $espessura));

        return redirect()->route('materiais.espessuras.index', $material)->with('status', 'Espessura atualizada.');
    }

    public function destroy(Material $material, EspessuraMaterial $espessura): RedirectResponse
    {
        $this->assertOwnership($material, $espessura);
        if ($espessura->chapas()->exists()) {
            return back()->withErrors(['espessura' => 'Há chapas vinculadas a esta espessura.']);
        }
        $espessura->delete();

        return redirect()->route('materiais.espessuras.index', $material)->with('status', 'Espessura excluída.');
    }

    private function assertOwnership(Material $material, EspessuraMaterial $espessura): void
    {
        abort_unless($espessura->material_id === $material->id, 404);
    }

    private function validated(Request $request, Material $material, ?EspessuraMaterial $espessura = null): array
    {
        $data = $request->validate([
            'valor_mm' => ['required', 'numeric', 'gt:0', 'max:99999.999', Rule::unique('espessuras_material', 'valor_mm')->where('material_id', $material->id)->ignore($espessura?->id)],
            'velocidade_corte_mm_min' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'tempo_perfuracao_seg' => ['required', 'numeric', 'gte:0', 'max:99999.999'],
            'kerf_mm' => ['nullable', 'numeric', 'gte:0', 'max:999.999'],
            'espacamento_minimo_mm' => ['required', 'numeric', 'min:0.001', 'max:100'],
            'lead_in_mm' => ['required', 'numeric', 'gt:0', 'max:100'],
            'custo_hora_maquina' => ['nullable', 'numeric', 'gte:0', 'max:99999999.99'],
        ]);
        $kerf = (float) ($data['kerf_mm'] ?? $espessura?->kerf_mm ?? 0);
        if ((float) $data['espacamento_minimo_mm'] <= $kerf) {
            throw ValidationException::withMessages(['espacamento_minimo_mm' => 'O espaçamento térmico deve ser maior que a largura do corte (kerf).']);
        }

        return $data;
    }
}
