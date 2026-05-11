@props([
    /** App\Models\StoryLine|null — the story_lines row to render. */
    'line' => null,
    /** Visual size: 'sm' = list-card peek, 'lg' = headline session detail. */
    'size' => 'lg',
])

@php
    $mood = $line?->mood ?? 'dim';
    $speech = $line?->speech ?? 'Hai! Temari belum punya cerita untuk run ini.';
    $sigil = $line?->sigil_pattern ?? 'dddd';

    $moodFace = match ($mood) {
        'glow' => '✨',
        'bouncy' => '🦘',
        'wobble' => '🥵',
        'squished' => '🍳',
        'spinning' => '💫',
        default => '🌧️',
    };

    $bubbleRing = match ($mood) {
        'glow' => 'ring-amber-300 dark:ring-amber-500/60',
        'bouncy' => 'ring-lime-300 dark:ring-lime-500/60',
        'wobble' => 'ring-rose-300 dark:ring-rose-500/60',
        'squished' => 'ring-orange-300 dark:ring-orange-500/60',
        'spinning' => 'ring-sky-300 dark:ring-sky-500/60',
        default => 'ring-slate-300 dark:ring-slate-500/60',
    };

    $mascotSize = $size === 'lg' ? 'h-20 w-20 text-3xl' : 'h-12 w-12 text-xl';
    $bodyPad = $size === 'lg' ? 'p-5' : 'p-3';
    $bodyText = $size === 'lg' ? 'text-base' : 'text-sm';
@endphp

<div {{ $attributes->merge(['class' => "flex items-start gap-4 rounded-2xl border border-black/5 bg-white {$bodyPad} dark:border-white/5 dark:bg-[#161615]"]) }}>
    <div class="relative shrink-0">
        {{-- Round mascot: face + sigil-pattern stitches around the rim. --}}
        <div class="{{ $mascotSize }} flex items-center justify-center rounded-full bg-gradient-to-br from-lime-200 to-lime-400 ring-4 {{ $bubbleRing }} dark:from-lime-700 dark:to-lime-500">
            <span>{{ $moodFace }}</span>
        </div>
        <span class="absolute -bottom-1 -right-1 rounded-full bg-white px-1.5 py-0.5 text-[10px] font-mono uppercase text-gray-500 ring-1 ring-black/10 dark:bg-[#0a0a0a] dark:text-gray-300 dark:ring-white/10">
            {{ $sigil }}
        </span>
    </div>
    <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2">
            <span class="text-sm font-semibold tracking-tight">Temari</span>
            <span class="text-[10px] uppercase tracking-wider text-gray-400 dark:text-gray-500">{{ $mood }}</span>
        </div>
        <p class="mt-1 {{ $bodyText }} leading-relaxed text-gray-700 dark:text-gray-200">
            {{ $speech }}
        </p>
    </div>
</div>
