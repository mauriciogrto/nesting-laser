<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold">Pedidos</h1></x-slot>
    <div class="mx-auto max-w-7xl space-y-4 p-6">
        <x-notice />
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-gray-600">Acompanhe produção, prazos, tempos e custos dos arranjos exportados.</p>
            <a class="rounded bg-indigo-700 px-4 py-2 text-white" href="{{ route('arranjos.create') }}">Novo arranjo em Otimizar</a>
        </div>
        <form method="GET" class="flex flex-wrap items-end gap-3 rounded bg-white p-4 shadow">
            <label class="text-sm">Status<select name="status" class="mt-1 block rounded border-gray-300">
                <option value="">Todos</option>
                @foreach(\App\Enums\StatusPedido::cases() as $status)<option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ str_replace('_', ' ', ucfirst($status->value)) }}</option>@endforeach
            </select></label>
            <label class="text-sm">Criado de<input type="date" name="de" value="{{ $filters['de'] ?? '' }}" class="mt-1 block rounded border-gray-300"></label>
            <label class="text-sm">Até<input type="date" name="ate" value="{{ $filters['ate'] ?? '' }}" class="mt-1 block rounded border-gray-300"></label>
            <button class="rounded border border-indigo-700 px-4 py-2 text-indigo-700">Filtrar</button>
            <a class="py-2 text-sm underline" href="{{ route('pedidos.index') }}">Limpar</a>
        </form>
        <div class="overflow-x-auto rounded bg-white p-4 shadow">
            <table class="w-full min-w-[950px] text-left text-sm"><thead><tr><th>Pedido</th><th>Status</th><th>Chapa</th><th>Criado</th><th>Previsão</th><th>Tempo</th><th>Material</th><th>Máquina</th><th>Total</th><th></th></tr></thead><tbody>
                @forelse($pedidos as $pedido)
                    <tr class="border-t"><td class="py-3">#{{ $pedido->id }}{{ $pedido->nome ? ' · '.$pedido->nome : '' }}</td><td>{{ str_replace('_', ' ', ucfirst($pedido->status->value)) }}</td><td>{{ $pedido->chapa ? '#'.$pedido->chapa->id.' · '.$pedido->chapa->material->nome : 'A definir' }}{{ $pedido->chapasInternas->isNotEmpty() ? ' ('.(1 + $pedido->chapasInternas->count()).' chapas)' : '' }}</td><td>{{ $pedido->created_at->format('d/m/Y') }}</td><td>{{ $pedido->data_prevista?->format('d/m/Y') ?? '—' }}</td><td>{{ $pedido->tempo_corte_real_seg ?? $pedido->tempo_corte_estimado_seg ?? '—' }}{{ $pedido->tempo_corte_real_seg || $pedido->tempo_corte_estimado_seg ? ' s' : '' }}</td><td>R$ {{ number_format((float) $pedido->custo_material, 2, ',', '.') }}</td><td>R$ {{ number_format((float) $pedido->custo_maquina, 2, ',', '.') }}</td><td class="font-semibold">R$ {{ number_format((float) $pedido->custo_total, 2, ',', '.') }}</td><td class="space-x-2 whitespace-nowrap"><a class="text-indigo-700 underline" href="{{ route('pedidos.show', $pedido) }}">Abrir</a>@if(!in_array($pedido->status, [\App\Enums\StatusPedido::AguardandoNesting, \App\Enums\StatusPedido::EmProducao, \App\Enums\StatusPedido::Concluido], true))<form method="POST" action="{{ route('pedidos.destroy', $pedido) }}" class="inline" onsubmit="return confirm('Excluir o Pedido #{{ $pedido->id }}? O estoque será revertido se o retalho ainda não tiver sido usado.');">@csrf @method('DELETE')<button class="text-red-700 underline">Excluir</button></form>@endif</td></tr>
                @empty
                    <tr><td colspan="10" class="py-4">Nenhum pedido encontrado.</td></tr>
                @endforelse
            </tbody></table>
        </div>
        {{ $pedidos->links() }}
    </div>
</x-app-layout>
