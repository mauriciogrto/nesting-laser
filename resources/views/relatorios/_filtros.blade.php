<form method="GET" action="{{ route($route) }}" class="flex flex-wrap items-end gap-3 rounded bg-white p-4 shadow">
    <label>De<input type="date" name="data_inicio" class="block rounded border-gray-300" value="{{ $filters['data_inicio'] }}"></label>
    <label>Até<input type="date" name="data_fim" class="block rounded border-gray-300" value="{{ $filters['data_fim'] }}"></label>
    <label>Material<select name="material_id" class="block rounded border-gray-300"><option value="">Todos</option>@foreach($materiais as $material)<option value="{{ $material->id }}" @selected($filters['material_id'] == $material->id)>{{ $material->nome }}</option>@endforeach</select></label>
    <button class="rounded bg-indigo-700 px-4 py-2 text-white">Filtrar</button>
</form>
