<?php

use App\Http\Controllers\ArranjoRapidoController;
use App\Http\Controllers\ChapaController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EditorPedidoController;
use App\Http\Controllers\EspessuraMaterialController;
use App\Http\Controllers\HistoricoAcaoController;
use App\Http\Controllers\MaterialController;
use App\Http\Controllers\MovimentacaoEstoqueController;
use App\Http\Controllers\PecaController;
use App\Http\Controllers\PedidoCorteController;
use App\Http\Controllers\ProducaoController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RelatorioController;
use App\Http\Controllers\UsuarioController;
use App\Models\Peca;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth', 'active'])->name('dashboard');

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('materiais', [MaterialController::class, 'index'])->name('materiais.index');
    Route::resource('materiais', MaterialController::class)->except(['show', 'index'])->parameters(['materiais' => 'material'])->middleware('can:admin');
    Route::resource('materiais.espessuras', EspessuraMaterialController::class)->except('show')->parameters(['materiais' => 'material', 'espessuras' => 'espessura'])->middleware('can:admin');
    Route::resource('chapas', ChapaController::class)->except('show');
    Route::resource('pecas', PecaController::class)->except('show');
    Route::get('pedidos', [PedidoCorteController::class, 'index'])->name('pedidos.index');
    Route::get('arranjos/novo', [ArranjoRapidoController::class, 'create'])->name('arranjos.create');
    Route::get('arranjos/pecas/buscar', [ArranjoRapidoController::class, 'buscarPecas'])->name('arranjos.pecas.buscar');
    Route::post('arranjos', [ArranjoRapidoController::class, 'store'])->name('arranjos.store');
    Route::get('arranjos/{token}', [ArranjoRapidoController::class, 'show'])->whereUuid('token')->name('arranjos.show');
    Route::get('arranjos/{token}/dados', [ArranjoRapidoController::class, 'data'])->whereUuid('token')->name('arranjos.data');
    Route::post('arranjos/{token}/posicoes', [ArranjoRapidoController::class, 'salvarPosicoes'])->whereUuid('token')->name('arranjos.posicoes');
    Route::post('arranjos/{token}/chapa', [ArranjoRapidoController::class, 'tentarOutraChapa'])->whereUuid('token')->name('arranjos.chapa');
    Route::post('arranjos/{token}/aceitar', [ArranjoRapidoController::class, 'aceitar'])->whereUuid('token')->name('arranjos.aceitar');
    Route::get('pedidos/create', [PedidoCorteController::class, 'create'])->name('pedidos.create');
    Route::post('pedidos', [PedidoCorteController::class, 'store'])->name('pedidos.store');
    Route::get('pedidos/{pedido}', [PedidoCorteController::class, 'show'])->name('pedidos.show');
    Route::get('pedidos/{pedido}/acrescentar-pecas', [ArranjoRapidoController::class, 'revisar'])->name('pedidos.acrescentar');
    Route::delete('pedidos/{pedido}', [PedidoCorteController::class, 'destroy'])->name('pedidos.destroy');
    Route::patch('pedidos/{pedido}/gestao', [PedidoCorteController::class, 'atualizarGestao'])->name('pedidos.gestao');
    Route::post('pedidos/{pedido}/confirmar', [PedidoCorteController::class, 'confirmar'])->name('pedidos.confirmar');
    Route::get('pedidos/{pedido}/status', [PedidoCorteController::class, 'status'])->name('pedidos.status');
    Route::get('pedidos/{pedido}/cnc', [PedidoCorteController::class, 'baixarCnc'])->name('pedidos.cnc');
    Route::get('pedidos/{pedido}/dxf', [PedidoCorteController::class, 'baixarDxf'])->name('pedidos.dxf');
    Route::get('pedidos/{pedido}/pdf', [PedidoCorteController::class, 'baixarPdf'])->name('pedidos.pdf');
    Route::get('pedidos/{pedido}/chapas/{numero}/cnc', [PedidoCorteController::class, 'baixarCncChapa'])->whereNumber('numero')->name('pedidos.chapas.cnc');
    Route::get('pedidos/{pedido}/chapas/{numero}/dxf', [PedidoCorteController::class, 'baixarDxfChapa'])->whereNumber('numero')->name('pedidos.chapas.dxf');
    Route::get('pedidos/{pedido}/chapas/{numero}/pdf', [PedidoCorteController::class, 'baixarPdfChapa'])->whereNumber('numero')->name('pedidos.chapas.pdf');
    Route::post('pedidos/{pedido}/concluir', [ProducaoController::class, 'concluir'])->name('pedidos.concluir');
    Route::post('pedidos/{pedido}/iniciar', [ProducaoController::class, 'iniciar'])->name('pedidos.iniciar');
    Route::get('movimentacoes', [MovimentacaoEstoqueController::class, 'index'])->name('movimentacoes.index');
    Route::middleware('can:admin')->group(function () {
        Route::resource('usuarios', UsuarioController::class)->except(['show', 'destroy'])->parameters(['usuarios' => 'usuario']);
        Route::get('historico', [HistoricoAcaoController::class, 'index'])->name('historico.index');
        Route::get('relatorios', [RelatorioController::class, 'index'])->name('relatorios.index');
        Route::get('relatorios/aproveitamento', [RelatorioController::class, 'aproveitamento'])->name('relatorios.aproveitamento');
        Route::get('relatorios/aproveitamento.csv', [RelatorioController::class, 'aproveitamentoCsv'])->name('relatorios.aproveitamento.csv');
        Route::get('relatorios/tempos', [RelatorioController::class, 'tempos'])->name('relatorios.tempos');
        Route::get('relatorios/consumo', [RelatorioController::class, 'consumo'])->name('relatorios.consumo');
        Route::get('relatorios/retalhos', [RelatorioController::class, 'retalhos'])->name('relatorios.retalhos');
    });
    Route::get('pedidos/{pedido}/editor', [EditorPedidoController::class, 'show'])->name('pedidos.editor');
    Route::get('pedidos/{pedido}/editor/dados', [EditorPedidoController::class, 'data'])->name('pedidos.editor.data');
    Route::post('pedidos/{pedido}/editor/recalcular', [EditorPedidoController::class, 'recalcular'])->name('pedidos.editor.recalcular');
    Route::post('pedidos/{pedido}/editor/organizar', [EditorPedidoController::class, 'organizar'])->name('pedidos.editor.organizar');
    Route::post('pedidos/{pedido}/editor/confirmar', [EditorPedidoController::class, 'confirmar'])->name('pedidos.editor.confirmar');
    Route::get('pecas-status', function (Request $request) {
        $data = $request->validate(['ids' => ['required', 'array', 'max:50'], 'ids.*' => ['integer', 'min:1']]);

        return response()->json(Peca::whereKey($data['ids'])->get(['id', 'status_processamento', 'erro_mensagem'])->mapWithKeys(
            fn (Peca $peca) => [$peca->id => ['status' => $peca->status_processamento->value, 'erro' => $peca->erro_mensagem]],
        ));
    })->name('pecas.statuses');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

require __DIR__.'/auth.php';
