<x-app-layout><x-slot name="header"><h1 class="text-xl font-semibold">Histórico de ações</h1></x-slot>
    <div class="mx-auto max-w-6xl space-y-4 p-6">
        <form method="GET" class="flex flex-wrap items-end gap-3 rounded bg-white p-4 shadow">
            <label>Usuário<select name="user_id" class="block rounded border-gray-300"><option value="">Todos</option>@foreach($usuarios as $usuario)<option value="{{ $usuario->id }}" @selected(($filters['user_id'] ?? '') == $usuario->id)>{{ $usuario->name }}</option>@endforeach</select></label>
            <label>Ação<select name="acao" class="block rounded border-gray-300"><option value="">Todas</option>@foreach($acoes as $acao)<option value="{{ $acao }}" @selected(($filters['acao'] ?? '') === $acao)>{{ str_replace('_', ' ', $acao) }}</option>@endforeach</select></label>
            <label>De<input type="date" name="data_inicio" class="block rounded border-gray-300" value="{{ $filters['data_inicio'] ?? '' }}"></label>
            <label>Até<input type="date" name="data_fim" class="block rounded border-gray-300" value="{{ $filters['data_fim'] ?? '' }}"></label>
            <button class="rounded bg-indigo-700 px-4 py-2 text-white">Filtrar</button>
        </form>
        <div class="overflow-x-auto rounded bg-white p-4 shadow"><table class="w-full text-left text-sm"><thead><tr><th>Data</th><th>Usuário</th><th>Ação</th><th>Entidade</th><th>Dados</th></tr></thead><tbody>
            @forelse($historico as $item)<tr class="border-t"><td class="py-2">{{ $item->created_at->format('d/m/Y H:i') }}</td><td>{{ $item->user?->name ?? 'Sistema' }}</td><td>{{ str_replace('_', ' ', $item->acao) }}</td><td>{{ $item->entidade }} #{{ $item->entidade_id }}</td><td><code class="break-all">{{ json_encode($item->dados_alterados, JSON_UNESCAPED_UNICODE) }}</code></td></tr>
            @empty<tr><td colspan="5" class="py-4">Nenhuma ação encontrada.</td></tr>@endforelse
        </tbody></table></div>{{ $historico->links() }}
    </div>
</x-app-layout>
