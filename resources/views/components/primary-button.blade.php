<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex min-h-[42px] items-center justify-center rounded-lg border border-transparent bg-[#4880ff] px-5 py-2 text-sm font-bold text-white transition hover:bg-[#6695ff] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#a9c0ff] disabled:cursor-not-allowed disabled:opacity-50']) }}>
    {{ $slot }}
</button>
