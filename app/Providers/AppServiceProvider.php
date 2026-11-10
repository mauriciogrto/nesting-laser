<?php

namespace App\Providers;

use App\Models\MovimentacaoEstoque;
use App\Models\PedidoCorte;
use App\Models\User;
use App\Observers\MovimentacaoEstoqueObserver;
use App\Observers\PedidoCorteObserver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('admin', fn (User $user): bool => $user->active && $user->role === 'admin');
        PedidoCorte::observe(PedidoCorteObserver::class);
        MovimentacaoEstoque::observe(MovimentacaoEstoqueObserver::class);
    }
}
