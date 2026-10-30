<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
</head>
<body class="min-h-dvh bg-stone-200 font-sans text-stone-900 antialiased">
<div class="mx-auto flex min-h-dvh w-full max-w-[480px] flex-col bg-white shadow-xl">
    <x-ui.app-header :shopName="$shopName">
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="flex min-h-[44px] items-center rounded-lg px-3 text-base font-medium text-stone-500 hover:text-stone-900">
                Log out
            </button>
        </form>
    </x-ui.app-header>

    <main class="flex-1 px-4 pb-32 pt-4">
        {{ $slot }}
    </main>

    <x-ui.tab-bar />
</div>
</body>
</html>
