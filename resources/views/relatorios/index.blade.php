<x-app-layout><x-slot name="header"><h1 class="text-xl font-semibold">Relatórios</h1></x-slot>
    <div class="mx-auto grid max-w-5xl gap-4 p-6 sm:grid-cols-2">
        @foreach(['aproveitamento' => 'Aproveitamento de material', 'tempos' => 'Tempo estimado × real', 'consumo' => 'Consumo de chapas', 'retalhos' => 'Retalhos gerados × reaproveitados'] as $name => $title)
            <a href="{{ route('relatorios.'.$name) }}" class="rounded bg-white p-6 font-semibold text-indigo-700 shadow hover:bg-indigo-50">{{ $title }} →</a>
        @endforeach
    </div>
</x-app-layout>
