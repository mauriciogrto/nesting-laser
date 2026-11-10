<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex min-h-[42px] items-center justify-center rounded-lg border border-[#5c80bd] bg-transparent px-5 py-2 text-sm font-bold text-[#b6ceff] transition hover:bg-[#344767] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#a9c0ff] disabled:cursor-not-allowed disabled:opacity-50']) }}>
    {{ $slot }}
</button>
