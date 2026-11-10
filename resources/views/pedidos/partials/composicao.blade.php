@if($composicao)
    <div class="mt-3 rounded-lg border border-slate-200 p-3 text-sm">
        <p class="font-semibold">Como a área da chapa ficou distribuída</p>
        <div class="mt-2 flex h-3 overflow-hidden rounded-full" role="img" aria-label="Peças {{ number_format($composicao['pecas_pct'], 2, ',', '.') }}%, retalho inteiro {{ number_format($composicao['retalho_pct'], 2, ',', '.') }}%, vazios e furos {{ number_format($composicao['vazios_pct'], 2, ',', '.') }}%">
            <span class="bg-blue-600" style="width: {{ $composicao['pecas_pct'] }}%"></span>
            <span class="bg-emerald-500" style="width: {{ $composicao['retalho_pct'] }}%"></span>
            <span class="bg-slate-300" style="width: {{ $composicao['vazios_pct'] }}%"></span>
        </div>
        <p class="mt-2">Peças: <strong>{{ number_format($composicao['pecas_pct'], 2, ',', '.') }}%</strong> · Retalho inteiro: <strong>{{ number_format($composicao['retalho_pct'], 2, ',', '.') }}%</strong> · Vazios e furos: <strong>{{ number_format($composicao['vazios_pct'], 2, ',', '.') }}%</strong></p>
        <p class="mt-1 text-slate-600">O retalho inteiro pode voltar ao estoque; os vazios e furos indicam a área que o encaixe ainda não recuperou.</p>
    </div>
@endif
