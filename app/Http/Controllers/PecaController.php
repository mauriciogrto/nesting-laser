<?php

namespace App\Http\Controllers;

use App\Enums\StatusProcessamento;
use App\Jobs\ExtrairGeometriaDxf;
use App\Models\Peca;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PecaController extends Controller
{
    public function index(): View
    {
        return view('pecas.index', ['pecas' => Peca::latest()->paginate(20)]);
    }

    public function create(): View
    {
        return view('pecas.form', ['peca' => new Peca]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, true);
        $nome = $this->nomeDaPeca($data['nome'] ?? null, $request->file('arquivo_dxf'));
        $path = $request->file('arquivo_dxf')->store('pecas', 'local');
        try {
            $peca = Peca::create(['nome' => $nome, 'arquivo_dxf_original' => $path]);
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }

        ExtrairGeometriaDxf::dispatch($peca->id);

        return redirect()->route('pecas.index')->with('status', 'Peça cadastrada. A geometria está sendo extraída.');
    }

    public function edit(Peca $peca): View
    {
        return view('pecas.form', compact('peca'));
    }

    public function update(Request $request, Peca $peca): RedirectResponse
    {
        $data = $this->validated($request, false);
        $nome = $this->nomeDaPeca($data['nome'] ?? null, $request->file('arquivo_dxf'), $peca->nome);
        $oldPath = $peca->arquivo_dxf_original;
        $newPath = $request->hasFile('arquivo_dxf') ? $request->file('arquivo_dxf')->store('pecas', 'local') : null;
        try {
            $peca->update([
                'nome' => $nome,
                'arquivo_dxf_original' => $newPath ?? $oldPath,
                'contorno_json' => $newPath ? null : $peca->contorno_json,
                'largura_bbox_mm' => $newPath ? null : $peca->largura_bbox_mm,
                'altura_bbox_mm' => $newPath ? null : $peca->altura_bbox_mm,
                'status_processamento' => $newPath ? StatusProcessamento::Pendente : $peca->status_processamento,
                'erro_mensagem' => $newPath ? null : $peca->erro_mensagem,
            ]);
        } catch (\Throwable $error) {
            if ($newPath) {
                Storage::disk('local')->delete($newPath);
            }
            throw $error;
        }
        if ($newPath) {
            Storage::disk('local')->delete($oldPath);
            ExtrairGeometriaDxf::dispatch($peca->id);
        }

        return redirect()->route('pecas.index')->with('status', $newPath ? 'Peça atualizada. A geometria está sendo extraída.' : 'Peça atualizada.');
    }

    public function destroy(Peca $peca): RedirectResponse
    {
        if ($peca->pedidos()->exists()) {
            return back()->withErrors(['peca' => 'Esta peça está vinculada a pedidos e não pode ser excluída.']);
        }
        $path = $peca->arquivo_dxf_original;
        $peca->delete();
        Storage::disk('local')->delete($path);

        return redirect()->route('pecas.index')->with('status', 'Peça excluída.');
    }

    private function validated(Request $request, bool $required): array
    {
        $data = $request->validate([
            'nome' => ['nullable', 'string', 'max:255'],
            'arquivo_dxf' => [$required ? 'required' : 'nullable', 'file', 'max:20480'],
        ]);
        $file = $request->file('arquivo_dxf');
        if ($file && strtolower($file->getClientOriginalExtension()) !== 'dxf') {
            throw ValidationException::withMessages(['arquivo_dxf' => 'Envie um arquivo com extensão .dxf.']);
        }

        return $data;
    }

    private function nomeDaPeca(?string $nome, ?UploadedFile $arquivo, ?string $nomeAtual = null): string
    {
        $nome = trim((string) $nome);
        if ($nome !== '') {
            return $nome;
        }

        if ($arquivo) {
            $nomeArquivo = trim(pathinfo($arquivo->getClientOriginalName(), PATHINFO_FILENAME));
            if ($nomeArquivo !== '') {
                return mb_substr($nomeArquivo, 0, 255);
            }
        }

        if ($nomeAtual !== null) {
            return $nomeAtual;
        }

        throw ValidationException::withMessages(['arquivo_dxf' => 'O arquivo DXF precisa ter um nome válido.']);
    }
}
