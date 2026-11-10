<x-app-layout>
    <x-slot name="header"><x-page-heading :back-href="route('pecas.index')">{{ $peca->exists ? 'Editar peça' : 'Enviar peça DXF' }}</x-page-heading></x-slot>
    <div class="mx-auto max-w-3xl p-6">
        <x-notice />
        <form class="space-y-4 rounded bg-white p-6 shadow" method="POST" enctype="multipart/form-data" action="{{ $peca->exists ? route('pecas.update', $peca) : route('pecas.store') }}">
            @csrf @if($peca->exists) @method('PUT') @endif
            <label class="block">Nome (opcional)<input class="mt-1 block w-full rounded border-gray-300" name="nome" maxlength="255" value="{{ old('nome', $peca->nome) }}" placeholder="Se vazio, usaremos o nome do arquivo DXF"></label>
            <label class="block">Arquivo DXF {{ $peca->exists ? '(opcional para manter o atual)' : '' }}<input class="mt-1 block w-full" type="file" name="arquivo_dxf" accept=".dxf" @required(!$peca->exists)></label>
            <p class="text-sm text-gray-600">Até 20 MB. A geometria será extraída automaticamente após salvar.</p>
            <button class="rounded bg-indigo-700 px-4 py-2 text-white">Salvar</button>
        </form>
    </div>
</x-app-layout>
