<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UsuarioController extends Controller
{
    public function index(): View
    {
        return view('usuarios.index', ['usuarios' => User::orderBy('name')->paginate(20)]);
    }

    public function create(): View
    {
        return view('usuarios.form', ['usuario' => new User]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(['admin', 'operador'])],
            'password' => ['required', 'string', 'min:12'],
        ]);
        User::create([...$data, 'active' => true]);

        return redirect()->route('usuarios.index')->with('status', 'Usuário criado.');
    }

    public function edit(User $usuario): View
    {
        return view('usuarios.form', compact('usuario'));
    }

    public function update(Request $request, User $usuario): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($usuario->id)],
            'role' => ['required', Rule::in(['admin', 'operador'])],
            'active' => ['required', 'boolean'],
            'password' => ['nullable', 'string', 'min:12'],
        ]);
        if ($usuario->is($request->user()) && ($data['role'] !== 'admin' || ! (bool) $data['active'])) {
            throw ValidationException::withMessages(['role' => 'Você não pode remover seu próprio acesso administrativo.']);
        }
        if ($usuario->role === 'admin' && ($data['role'] !== 'admin' || ! (bool) $data['active'])
            && User::where('role', 'admin')->where('active', true)->count() <= 1) {
            throw ValidationException::withMessages(['role' => 'Mantenha pelo menos um administrador ativo.']);
        }
        if (! filled($data['password'] ?? null)) {
            unset($data['password']);
        }
        $usuario->update($data);

        return redirect()->route('usuarios.index')->with('status', 'Usuário atualizado.');
    }
}
