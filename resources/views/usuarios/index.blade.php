<x-app-layout><x-slot name="header"><h1 class="text-xl font-semibold">Usuários</h1></x-slot>
    <div class="mx-auto max-w-5xl space-y-4 p-6"><x-notice />
        <a class="inline-block rounded bg-indigo-700 px-4 py-2 text-white" href="{{ route('usuarios.create') }}">Novo usuário</a>
        <div class="overflow-x-auto rounded bg-white p-4 shadow"><table class="w-full text-left"><thead><tr><th>Nome</th><th>Email</th><th>Papel</th><th>Situação</th><th></th></tr></thead><tbody>
            @forelse($usuarios as $usuario)<tr class="border-t"><td class="py-2">{{ $usuario->name }}</td><td>{{ $usuario->email }}</td><td>{{ ucfirst($usuario->role) }}</td><td>{{ $usuario->active ? 'Ativo' : 'Desativado' }}</td><td><a class="underline" href="{{ route('usuarios.edit', $usuario) }}">Editar</a></td></tr>
            @empty<tr><td colspan="5" class="py-4">Nenhum usuário.</td></tr>@endforelse
        </tbody></table></div>{{ $usuarios->links() }}
    </div>
</x-app-layout>
