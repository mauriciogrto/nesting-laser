<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold">Chapas em estoque</h1></x-slot>
    <div class="mx-auto max-w-6xl p-6">
        <x-notice />
        <div class="flex flex-wrap items-center gap-3"><a class="app-action-primary" href="{{ route('chapas.create') }}">＋ Nova chapa</a>
        <a class="app-action-secondary" href="{{ route('movimentacoes.index') }}">Histórico do estoque</a></div>
        <form method="GET" class="my-4 flex flex-wrap items-end gap-3 rounded bg-white p-4 shadow">
            <label>Material<select class="block rounded border-gray-300" name="material_id"><option value="">Todos</option>@foreach($materiais as $material)<option value="{{ $material->id }}" @selected(($filters['material_id'] ?? '') == $material->id)>{{ $material->nome }}</option>@endforeach</select></label>
            <label>Status<select class="block rounded border-gray-300" name="status"><option value="">Todos</option>@foreach(\App\Enums\StatusChapa::cases() as $status)<option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ ucfirst($status->value) }}</option>@endforeach</select></label>
            <button class="rounded bg-indigo-700 px-4 py-2 text-white">Filtrar</button>
        </form>
        <div class="overflow-x-auto rounded bg-white p-4 shadow">
            <table class="w-full text-left"><thead><tr><th>#</th><th>Material</th><th>Espessura</th><th>Dimensões</th><th>Origem</th><th>Disponível</th><th>Status</th><th>Ações</th></tr></thead><tbody>
                @forelse($chapas as $chapa)
                    <tr class="border-t"><td class="py-2">{{ $chapa->id }}</td><td>{{ $chapa->material->nome }}</td><td>{{ $chapa->espessura->valor_mm }} mm</td><td>{{ $chapa->largura_mm }} × {{ $chapa->altura_mm }} mm</td><td><span class="rounded px-2 py-1 text-xs font-semibold {{ $chapa->origem === \App\Enums\OrigemChapa::Retalho ? 'bg-amber-100 text-amber-900' : 'bg-slate-100 text-slate-700' }}">{{ ucfirst($chapa->origem->value) }}</span></td><td>{{ $chapa->quantidade_disponivel }}</td><td>{{ ucfirst($chapa->status->value) }}</td><td><a class="underline" href="{{ route('chapas.edit', $chapa) }}">Editar</a><form method="POST" class="inline" action="{{ route('chapas.destroy', $chapa) }}" onsubmit="return confirm('Excluir chapa?')">@csrf @method('DELETE')<button class="text-red-700 underline">Excluir</button></form></td></tr>
                @empty
                    <tr><td class="py-4" colspan="8">Nenhuma chapa encontrada.</td></tr>
                @endforelse
            </tbody></table>
        </div>
        <div class="mt-4">{{ $chapas->links() }}</div>
    </div>
</x-app-layout>
