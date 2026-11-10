<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex min-h-[42px] items-center justify-center rounded-lg border border-transparent bg-red-600 px-5 py-2 text-sm font-bold text-white transition hover:bg-red-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-300 disabled:cursor-not-allowed disabled:opacity-50']) }}>
    {{ $slot }}
</button>
