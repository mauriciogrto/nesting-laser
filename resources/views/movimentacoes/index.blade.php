<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold">Movimentações de estoque</h1></x-slot>
    <div class="mx-auto max-w-6xl p-6"><x-notice />
        <form method="GET" class="mb-4 flex flex-wrap items-end gap-3 rounded bg-white p-4 shadow">
            <label>Chapa #<input class="block rounded border-gray-300" type="number" min="1" name="chapa_id" value="{{ $filters['chapa_id'] ?? '' }}"></label>
            <label>Tipo<select class="block rounded border-gray-300" name="tipo"><option value="">Todos</option>@foreach(\App\Enums\TipoMovimentacao::cases() as $tipo)<option value="{{ $tipo->value }}" @selected(($filters['tipo'] ?? '') === $tipo->value)>{{ str_replace('_', ' ', ucfirst($tipo->value)) }}</option>@endforeach</select></label>
            <button class="rounded bg-indigo-700 px-4 py-2 text-white">Filtrar</button>
        </form>
        <div class="overflow-x-auto rounded bg-white p-4 shadow"><table class="w-full text-left"><thead><tr><th>Data</th><th>Chapa</th><th>Material</th><th>Tipo</th><th>Qtd.</th><th>Pedido</th><th>Observação</th></tr></thead><tbody>
            @forelse($movimentacoes as $movimento)<tr class="border-t"><td class="py-2">{{ $movimento->created_at->format('d/m/Y H:i') }}</td><td>#{{ $movimento->chapa_id }}</td><td>{{ $movimento->chapa->material->nome }}</td><td>{{ str_replace('_', ' ', $movimento->tipo->value) }}</td><td>{{ $movimento->quantidade }}</td><td>@if($movimento->pedido)<a class="underline" href="{{ route('pedidos.show', $movimento->pedido) }}">#{{ $movimento->pedido->id }}</a>@endif</td><td>{{ $movimento->observacao }}</td></tr>
            @empty<tr><td colspan="7" class="py-4">Nenhuma movimentação encontrada.</td></tr>@endforelse
        </tbody></table></div><div class="mt-4">{{ $movimentacoes->links() }}</div>
    </div>
</x-app-layout>
