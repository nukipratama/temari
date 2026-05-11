@props([
    /** App\Models\RunCard */
    'card',
    /** App\Models\ActivityDetail */
    'detail',
])

@php
    use App\Models\RunCard;
    use App\Services\Run\Metrics\PaceFormatter;

    $rarityRing = match ($card->rarity) {
        RunCard::RARITY_LEGENDARIS => 'ring-amber-400 dark:ring-amber-300',
        RunCard::RARITY_EPIK => 'ring-violet-400 dark:ring-violet-300',
        RunCard::RARITY_LANGKA => 'ring-sky-400 dark:ring-sky-300',
        RunCard::RARITY_JARANG => 'ring-emerald-400 dark:ring-emerald-300',
        default => 'ring-slate-300 dark:ring-slate-500',
    };

    $rarityChipBg = match ($card->rarity) {
        RunCard::RARITY_LEGENDARIS => 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
        RunCard::RARITY_EPIK => 'bg-violet-500/15 text-violet-700 dark:text-violet-300',
        RunCard::RARITY_LANGKA => 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
        RunCard::RARITY_JARANG => 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
        default => 'bg-slate-500/10 text-slate-600 dark:text-slate-300',
    };

    $distanceKm = $detail->distance ? number_format($detail->distance / 1000, 2, '.', '') : '—';
    $movingTime = $detail->moving_time ? PaceFormatter::format($detail->moving_time) : '—';
@endphp

<article {{ $attributes->merge(['class' => "rounded-3xl bg-white p-6 ring-4 {$rarityRing} dark:bg-[#161615]"]) }}>
    <header class="flex items-start justify-between gap-3">
        <div>
            <h3 class="text-lg font-black tracking-tight">{{ $card->special_move }}</h3>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $detail->name ?? 'Run' }}</p>
        </div>
        <span class="text-[10px] font-bold uppercase tracking-widest px-2 py-1 rounded-full {{ $rarityChipBg }}">
            {{ RunCard::RARITY_LABELS[$card->rarity] ?? $card->rarity }}
        </span>
    </header>

    <div class="mt-5 grid grid-cols-3 gap-3 text-center">
        <div>
            <div class="text-2xl font-black tabular-nums">{{ $distanceKm }}</div>
            <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">km</div>
        </div>
        <div>
            <div class="text-2xl font-black tabular-nums">{{ $movingTime }}</div>
            <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">durasi</div>
        </div>
        <div>
            <div class="text-2xl font-black tabular-nums">
                {{ $detail->trimp_edwards !== null ? (int) round($detail->trimp_edwards) : '—' }}
            </div>
            <div class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">TRIMP</div>
        </div>
    </div>

    @if (! empty($card->badges))
        <ul class="mt-5 flex flex-wrap gap-2">
            @foreach ($card->badges as $badgeKey)
                <li class="text-[11px] font-semibold px-2 py-1 rounded-full bg-black/[0.04] dark:bg-white/[0.06]">
                    {{ RunCard::BADGE_LABELS[$badgeKey] ?? $badgeKey }}
                </li>
            @endforeach
        </ul>
    @endif
</article>
