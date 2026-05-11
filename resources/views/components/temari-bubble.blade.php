@props([
    /** App\Models\StoryLine|null — the story_lines row to render. */
    'line' => null,
    /** Visual size: 'sm' = list-card peek, 'lg' = headline session detail. */
    'size' => 'lg',
])

@php
    use App\Services\Run\Story\Temari;

    $mood = $line?->mood ?? Temari::MOOD_DIM;
    $speech = $line?->speech ?? 'Hai! Temari belum punya cerita untuk run ini.';
    $sigil = $line?->sigil_pattern ?? 'dddd';

    $moodFace = match ($mood) {
        Temari::MOOD_GLOW => '✨',
        Temari::MOOD_BOUNCY => '🦘',
        Temari::MOOD_WOBBLE => '🥵',
        Temari::MOOD_SQUISHED => '🍳',
        Temari::MOOD_SPINNING => '💫',
        default => '🌧️',
    };

    $bubbleRing = match ($mood) {
        Temari::MOOD_GLOW => 'ring-amber-300 dark:ring-amber-500/60',
        Temari::MOOD_BOUNCY => 'ring-lime-300 dark:ring-lime-500/60',
        Temari::MOOD_WOBBLE => 'ring-rose-300 dark:ring-rose-500/60',
        Temari::MOOD_SQUISHED => 'ring-orange-300 dark:ring-orange-500/60',
        Temari::MOOD_SPINNING => 'ring-sky-300 dark:ring-sky-500/60',
        default => 'ring-slate-300 dark:ring-slate-500/60',
    };

    $sigilColor = match ($mood) {
        Temari::MOOD_GLOW => '#d97706',
        Temari::MOOD_BOUNCY => '#65a30d',
        Temari::MOOD_WOBBLE => '#e11d48',
        Temari::MOOD_SQUISHED => '#ea580c',
        Temari::MOOD_SPINNING => '#0284c7',
        default => '#64748b',
    };

    $mascotSize = $size === 'lg' ? 'h-24 w-24 text-3xl' : 'h-14 w-14 text-xl';
    $sigilSize = $size === 'lg' ? 96 : 56;
    $bodyPad = $size === 'lg' ? 'p-5' : 'p-3';
    $bodyText = $size === 'lg' ? 'text-base' : 'text-sm';
@endphp

<div {{ $attributes->merge(['class' => "flex items-start gap-4 rounded-2xl border border-black/5 bg-white {$bodyPad} dark:border-white/5 dark:bg-[#161615]"]) }}>
    <div class="relative shrink-0">
        {{-- Round mascot: face inside, sigil stitches around the rim. --}}
        <div class="{{ $mascotSize }} relative flex items-center justify-center rounded-full bg-gradient-to-br from-lime-200 to-lime-400 ring-4 {{ $bubbleRing }} dark:from-lime-700 dark:to-lime-500">
            <span class="relative z-10">{{ $moodFace }}</span>
            <x-temari-sigil :pattern="$sigil" :size="$sigilSize" :color="$sigilColor"
                            class="absolute inset-0 mix-blend-multiply dark:mix-blend-screen" />
        </div>
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
