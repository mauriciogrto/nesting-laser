<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold">Materiais</h1></x-slot>
    <div class="mx-auto max-w-5xl p-6">
        <x-notice />
        @can('admin')<a class="app-action-primary" href="{{ route('materiais.create') }}">＋ Novo material</a>@endcan
        <div class="mt-4 overflow-x-auto rounded bg-white p-4 shadow">
            <table class="w-full text-left">
                <thead><tr><th>Nome</th><th>Espessuras</th><th>Chapas</th><th>Ações</th></tr></thead>
                <tbody>
                @forelse ($materiais as $material)
                    <tr class="border-t"><td class="py-2">{{ $material->nome }}</td><td>{{ $material->espessuras->pluck('valor_mm')->map(fn ($valor) => $valor.' mm')->join(', ') ?: 'Nenhuma' }}</td><td>{{ $material->chapas_count }}</td>
                        <td class="space-x-3">@can('admin')<a class="underline" href="{{ route('materiais.espessuras.index', $material) }}">Espessuras</a><a class="underline" href="{{ route('materiais.edit', $material) }}">Editar</a>
                            <form class="inline" method="POST" action="{{ route('materiais.destroy', $material) }}" onsubmit="return confirm('Excluir material?')">@csrf @method('DELETE')<button class="text-red-700 underline">Excluir</button></form>
                            @endcan
                        </td></tr>
                @empty
                    <tr><td colspan="4" class="py-4">Nenhum material cadastrado.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $materiais->links() }}</div>
    </div>
</x-app-layout>
