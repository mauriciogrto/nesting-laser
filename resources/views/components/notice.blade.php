@if (session('status'))
    <p class="mb-4 rounded bg-green-50 p-3 text-green-900" role="status">{{ session('status') }}</p>
@endif
@if ($errors->any())
    <div class="mb-4 rounded bg-red-50 p-3 text-red-900" role="alert">
        <ul class="list-disc pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
