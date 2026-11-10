<?php

namespace App\Http\Controllers;

use App\Models\Material;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MaterialController extends Controller
{
    public function index(): View
    {
        return view('materiais.index', ['materiais' => Material::withCount(['espessuras', 'chapas'])
            ->with(['espessuras' => fn ($query) => $query->orderBy('valor_mm')])->orderBy('nome')->paginate(20)]);
    }

    public function create(): View
    {
        return view('materiais.form', ['material' => new Material]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nome' => ['required', 'string', 'max:255', Rule::unique('materiais', 'nome')],
            'estoque_minimo_alerta' => ['sometimes', 'integer', 'min:0', 'max:999999'],
            'densidade_kg_m3' => ['nullable', 'numeric', 'gt:0', 'max:30000'],
        ]);
        Material::create($data);

        return redirect()->route('materiais.index')->with('status', 'Material cadastrado.');
    }

    public function edit(Material $material): View
    {
        return view('materiais.form', ['material' => $material]);
    }

    public function update(Request $request, Material $material): RedirectResponse
    {
        $data = $request->validate([
            'nome' => ['required', 'string', 'max:255', Rule::unique('materiais', 'nome')->ignore($material->id)],
            'estoque_minimo_alerta' => ['sometimes', 'integer', 'min:0', 'max:999999'],
            'densidade_kg_m3' => ['nullable', 'numeric', 'gt:0', 'max:30000'],
        ]);
        $material->update($data);

        return redirect()->route('materiais.index')->with('status', 'Material atualizado.');
    }

    public function destroy(Material $material): RedirectResponse
    {
        if ($material->espessuras()->exists() || $material->chapas()->exists()) {
            return back()->withErrors(['material' => 'Remova as espessuras e chapas vinculadas antes de excluir o material.']);
        }
        $material->delete();

        return redirect()->route('materiais.index')->with('status', 'Material excluído.');
    }
}
