<x-app-layout><x-slot name="header"><x-page-heading :back-href="route('usuarios.index')">{{ $usuario->exists ? 'Editar usuário' : 'Novo usuário' }}</x-page-heading></x-slot>
    <div class="mx-auto max-w-3xl p-6"><x-notice />
        <form method="POST" action="{{ $usuario->exists ? route('usuarios.update', $usuario) : route('usuarios.store') }}" class="space-y-4 rounded bg-white p-6 shadow">@csrf @if($usuario->exists) @method('PUT') @endif
            <label class="block">Nome<input class="mt-1 block w-full rounded border-gray-300" name="name" required value="{{ old('name', $usuario->name) }}"></label>
            <label class="block">Email<input class="mt-1 block w-full rounded border-gray-300" name="email" type="email" required value="{{ old('email', $usuario->email) }}"></label>
            <label class="block">Papel<select class="mt-1 block w-full rounded border-gray-300" name="role"><option value="operador" @selected(old('role', $usuario->role) === 'operador')>Operador</option><option value="admin" @selected(old('role', $usuario->role) === 'admin')>Administrador</option></select></label>
            <label class="block">{{ $usuario->exists ? 'Nova senha (deixe vazio para manter)' : 'Senha' }}<input class="mt-1 block w-full rounded border-gray-300" name="password" type="password" minlength="12" @required(! $usuario->exists) autocomplete="new-password"></label>
            @if($usuario->exists)<input type="hidden" name="active" value="0"><label class="flex items-center gap-2"><input type="checkbox" name="active" value="1" @checked(old('active', $usuario->active))>Usuário ativo</label>@endif
            <button class="rounded bg-indigo-700 px-4 py-2 text-white">Salvar</button>
        </form>
    </div>
</x-app-layout>
