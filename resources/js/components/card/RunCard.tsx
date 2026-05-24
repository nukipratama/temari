import { Icon } from '@iconify/react';
import { cn } from '@/lib/cn';
import { formatDuration, formatIdDate, formatKm } from '@/lib/pace';
import { BADGE_LABELS, RARITY_LABELS } from '@/lib/runcard';
import type { ActivityDetail, RunCard as RunCardModel } from '@/types/inertia';

interface RunCardProps {
    card: RunCardModel;
    detail: ActivityDetail;
    className?: string;
    /** When true, render the card at hero scale (used by the Featured slot). */
    size?: 'normal' | 'hero';
}

interface RarityStyle {
    icon: string;
    ringClass: string;
    chipClass: string;
    bgClass: string;
    cornerClass: string;
    holographic: boolean;
}

const RARITY_STYLES: Record<string, RarityStyle> = {
    legendaris: {
        icon: 'mdi:crown',
        ringClass: 'ring-citrus shadow-citrus/40',
        chipClass: 'bg-citrus text-white ring-2 ring-white',
        bgClass: 'bg-gradient-to-br from-citrus/15 via-citrus/10 to-horizon/15',
        cornerClass: 'from-citrus/40 to-citrus',
        holographic: true,
    },
    epik: {
        icon: 'mdi:star-four-points',
        ringClass: 'ring-horizon shadow-horizon/40',
        chipClass: 'bg-horizon text-white ring-2 ring-white',
        bgClass: 'bg-gradient-to-br from-horizon/15 via-horizon/10 to-citrus/10',
        cornerClass: 'from-horizon/40 to-horizon',
        holographic: false,
    },
    langka: {
        icon: 'mdi:star',
        ringClass: 'ring-mood-mumet shadow-mood-mumet/30',
        chipClass: 'bg-mood-mumet text-white ring-2 ring-white',
        bgClass: 'bg-gradient-to-br from-mood-mumet/20 via-surface-elev to-mood-mumet/10',
        cornerClass: 'from-mood-mumet/50 to-mood-mumet',
        holographic: false,
    },
    jarang: {
        icon: 'mdi:star-outline',
        ringClass: 'ring-leaf shadow-leaf/25',
        chipClass: 'bg-leaf text-white ring-2 ring-white',
        bgClass: 'bg-gradient-to-br from-leaf/15 via-surface-elev to-leaf/10',
        cornerClass: 'from-leaf/40 to-leaf',
        holographic: false,
    },
};

const RARITY_DEFAULT: RarityStyle = {
    icon: 'mdi:circle-outline',
    ringClass: 'ring-line',
    chipClass: 'bg-ink-3 text-white ring-2 ring-white',
    bgClass: 'bg-surface-elev',
    cornerClass: 'from-line to-line',
    holographic: false,
};

function rarityStyle(rarity: string): RarityStyle {
    return RARITY_STYLES[rarity] ?? RARITY_DEFAULT;
}

export default function RunCard({ card, detail, className, size = 'normal' }: Readonly<RunCardProps>) {
    const r = rarityStyle(card.rarity);
    const km = formatKm(detail.distance);
    const duration = detail.moving_time != null ? formatDuration(detail.moving_time) : '—';
    const trimp = detail.trimp_edwards != null ? Math.round(detail.trimp_edwards) : '—';
    const isHero = size === 'hero';

    return (
        <article
            className={cn(
                'group relative flex h-full flex-col overflow-hidden rounded-2xl shadow-md ring-2 transition hover:shadow-xl sm:rounded-3xl',
                isHero ? 'p-5 ring-4 shadow-lg sm:p-7' : 'p-4 ring-2 sm:p-5',
                r.ringClass,
                r.bgClass,
                className,
            )}
        >
            {/* Diagonal corner accent — bigger + brighter for higher rarities */}
            <span
                aria-hidden
                className={cn(
                    'absolute -right-8 -top-8 rotate-45 bg-gradient-to-br opacity-70',
                    isHero ? 'h-20 w-20' : 'h-16 w-16',
                    r.cornerClass,
                )}
            />
            {/* Holographic shimmer band for legendaris — pure CSS, no animation cost */}
            {r.holographic && (
                <span
                    aria-hidden
                    className="absolute inset-0 -z-0 bg-[linear-gradient(115deg,transparent_30%,rgba(255,255,255,0.45)_50%,transparent_70%)] opacity-60 transition group-hover:opacity-90"
                />
            )}

            <header className="relative flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h3
                        className={cn(
                            'font-black tracking-tight text-ink',
                            isHero ? 'text-2xl' : 'text-lg',
                        )}
                    >
                        {card.special_move}
                    </h3>
                    <p className="mt-0.5 truncate text-sm font-medium text-ink-2">
                        {detail.name ?? 'Run'}
                    </p>
                    <p className="mt-0.5 text-xs text-ink-3">
                        {formatIdDate(detail.start_date_local)}
                    </p>
                </div>
                <span
                    className={cn(
                        'inline-flex shrink-0 items-center gap-1 rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-widest shadow-sm',
                        r.chipClass,
                    )}
                >
                    <Icon icon={r.icon} width={12} height={12} aria-hidden />
                    {RARITY_LABELS[card.rarity] ?? card.rarity}
                </span>
            </header>

            <div
                className={cn(
                    'relative grid grid-cols-3 gap-3 text-center',
                    isHero ? 'mt-7' : 'mt-5',
                )}
            >
                <Stat value={km} unit="km" size={size} />
                <Stat value={duration} unit="durasi" size={size} />
                <Stat value={trimp} unit="TRIMP" size={size} />
            </div>

            {card.badges && card.badges.length > 0 && (
                <ul className="relative mt-auto flex flex-wrap gap-2 pt-5">
                    {card.badges.map((b) => (
                        <li
                            key={b}
                            className="rounded-full bg-surface-elev/80 px-2.5 py-1 text-[11px] font-semibold text-ink shadow-sm ring-1 ring-line"
                        >
                            {BADGE_LABELS[b] ?? b}
                        </li>
                    ))}
                </ul>
            )}
        </article>
    );
}

interface StatProps {
    value: string | number;
    unit: string;
    size: 'normal' | 'hero';
}

function Stat({ value, unit, size }: Readonly<StatProps>) {
    const valueClass = size === 'hero' ? 'text-3xl' : 'text-2xl';
    return (
        <div className="min-w-0">
            <div className={cn('truncate font-black tabular-nums text-ink', valueClass)}>{value}</div>
            <div className="text-[10px] uppercase tracking-wide text-ink-3">{unit}</div>
        </div>
    );
}
