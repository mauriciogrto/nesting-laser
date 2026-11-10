@props(['backHref', 'backLabel' => 'Voltar'])

<div class="flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-xl font-semibold">{{ $slot }}</h1>
    <a href="{{ $backHref }}" class="app-action-secondary ml-auto shrink-0">← {{ $backLabel }}</a>
</div>
