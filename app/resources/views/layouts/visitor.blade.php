<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
</head>
<body class="min-h-dvh bg-stone-200 font-sans text-stone-900 antialiased">
<div class="mx-auto flex min-h-dvh w-full max-w-[480px] flex-col bg-white shadow-xl">
    <x-ui.app-header shopName="Mahadi Store" />
    <main class="flex-1 px-4 pb-8 pt-4">
        {{ $slot }}
    </main>
</div>
</body>
</html>
