@props(['steps', 'current', 'seconds' => []])

@php($total = count($steps))

<ol class="space-y-1" aria-label="Scan progress">
    @foreach ($steps as $index => $step)
        @php($done = $index < $current || $current >= $total)
        @php($active = $index === $current)
        <li @class(['flex min-h-[44px] items-center gap-3 rounded-xl px-2', 'bg-leaf-50' => $active]) @if($active) aria-current="step" @endif>
            @if ($done)
                <span class="step-tick flex size-6 shrink-0 items-center justify-center rounded-full bg-leaf-700 text-white" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor" class="size-3.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                    </svg>
                </span>
            @elseif ($active)
                <span class="flex size-6 shrink-0 items-center justify-center" aria-hidden="true">
                    <span class="size-3 rounded-full bg-leaf-600 motion-safe:animate-pulse"></span>
                </span>
            @else
                <span class="flex size-6 shrink-0 items-center justify-center" aria-hidden="true">
                    <span class="size-3 rounded-full border-2 border-stone-300"></span>
                </span>
            @endif
            <span @class(['text-base', 'font-semibold text-stone-900' => $active, 'text-stone-700' => ! $active && $done, 'text-stone-500' => ! $active && ! $done])>{{ $step }}</span>
            @php($secondsForStep = $seconds[$index] ?? null)
            @if ($done && is_numeric($secondsForStep))
                <span class="tnum ml-auto pl-2 text-base text-stone-500">{{ $secondsForStep }}s</span>
            @endif
        </li>
    @endforeach
</ol>
