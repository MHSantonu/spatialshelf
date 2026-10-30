@props(['title', 'sub' => null])

<div>
    <h1 class="text-xl font-bold text-stone-900">{{ $title }}</h1>
    @if ($sub)
        <p class="mt-1 text-base text-stone-600">{{ $sub }}</p>
    @endif
</div>
