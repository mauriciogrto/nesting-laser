<?php

namespace App\Http\Controllers;

use App\Enums\OrigemChapa;
use App\Enums\StatusChapa;
use App\Models\Chapa;
use App\Models\EspessuraMaterial;
use App\Models\Material;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ChapaController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'material_id' => ['nullable', 'integer', 'exists:materiais,id'],
            'status' => ['nullable', Rule::enum(StatusChapa::class)],
        ]);
        $chapas = Chapa::with(['material', 'espessura'])
            ->when($filters['material_id'] ?? null, fn ($query, $id) => $query->where('material_id', $id))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByRaw("CASE WHEN status = 'disponivel' AND origem = 'retalho' THEN 0 WHEN status = 'disponivel' THEN 1 ELSE 2 END")
            ->orderBy('material_id')->orderBy('espessura_id')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('chapas.index', ['chapas' => $chapas, 'materiais' => Material::orderBy('nome')->get(), 'filters' => $filters]);
    }

    public function create(): View
    {
        return $this->form(new Chapa);
    }

    public function store(Request $request): RedirectResponse
    {
        Chapa::create([
            ...$this->validated($request),
            'origem' => OrigemChapa::Nova,
            'quantidade_disponivel' => 1,
            'status' => StatusChapa::Disponivel,
            'custo_unitario' => 0,
        ]);

        return redirect()->route('chapas.index')->with('status', 'Chapa cadastrada.');
    }

    public function edit(Chapa $chapa): View
    {
        return $this->form($chapa);
    }

    public function update(Request $request, Chapa $chapa): RedirectResponse
    {
        $chapa->update($this->validated($request));

        return redirect()->route('chapas.index')->with('status', 'Chapa atualizada.');
    }

    public function destroy(Chapa $chapa): RedirectResponse
    {
        if ($chapa->pedidos()->exists() || $chapa->movimentacoes()->exists() || $chapa->retalhos()->exists()) {
            return back()->withErrors(['chapa' => 'Esta chapa possui histórico e não pode ser excluída.']);
        }
        $chapa->delete();

        return redirect()->route('chapas.index')->with('status', 'Chapa excluída.');
    }

    private function form(Chapa $chapa): View
    {
        return view('chapas.form', [
            'chapa' => $chapa,
            'materiais' => Material::orderBy('nome')->get(),
            'espessuras' => EspessuraMaterial::with('material')->orderBy('valor_mm')->get(),
        ]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'material_id' => ['required', 'integer', 'exists:materiais,id'],
            'espessura_id' => ['required', 'integer', 'exists:espessuras_material,id'],
            'largura_mm' => ['required', 'numeric', 'gt:0', 'max:999999999.999'],
            'altura_mm' => ['required', 'numeric', 'gt:0', 'max:999999999.999'],
        ]);

        $thickness = EspessuraMaterial::findOrFail($data['espessura_id']);
        if ($thickness->material_id !== (int) $data['material_id']) {
            throw ValidationException::withMessages(['espessura_id' => 'A espessura não pertence ao material selecionado.']);
        }

        return $data;
    }
}
