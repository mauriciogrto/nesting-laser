<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold">Peças</h1></x-slot>
    <div class="mx-auto max-w-5xl p-6">
        <x-notice />
        <a class="app-action-primary" href="{{ route('pecas.create') }}">＋ Enviar peça DXF</a>
        <div class="mt-4 overflow-x-auto rounded bg-white p-4 shadow">
            <table class="w-full text-left"><thead><tr><th>Peça</th><th>Formato</th><th>Dimensões</th><th>Enviada em</th><th>Ações</th></tr></thead><tbody>
            @forelse($pecas as $peca)
                <tr class="border-t"><td class="py-2 font-semibold">{{ $peca->nome }}</td><td><span class="rounded bg-gray-100 px-2 py-1 text-xs font-semibold">DXF</span></td><td>{{ $peca->largura_bbox_mm && $peca->altura_bbox_mm ? $peca->largura_bbox_mm.' × '.$peca->altura_bbox_mm.' mm' : '—' }}</td><td>{{ $peca->created_at->format('d/m/Y H:i') }}</td><td><a class="underline" href="{{ route('pecas.edit', $peca) }}">Editar</a><form method="POST" class="inline" action="{{ route('pecas.destroy', $peca) }}" onsubmit="return confirm('Excluir peça e arquivo DXF?')">@csrf @method('DELETE')<button class="text-red-700 underline">Excluir</button></form></td></tr>
            @empty
                <tr><td colspan="5" class="py-4">Nenhuma peça cadastrada.</td></tr>
            @endforelse
            </tbody></table>
        </div>
        <div class="mt-4">{{ $pecas->links() }}</div>
    </div>
</x-app-layout>
