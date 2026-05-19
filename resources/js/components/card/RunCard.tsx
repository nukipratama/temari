import { Icon } from '@iconify/react';
import { cn } from '@/lib/cn';
import { formatDuration, formatIdDate } from '@/lib/pace';
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

function rarityStyle(rarity: string): RarityStyle {
    switch (rarity) {
        case 'legendaris':
            return {
                icon: 'mdi:crown',
                ringClass: 'ring-pop-500 shadow-pop-300/40',
                chipClass: 'bg-pop-500 text-white ring-2 ring-white',
                bgClass: 'bg-gradient-to-br from-pop-100 via-pop-50 to-accent-100/60',
                cornerClass: 'from-pop-300 to-pop-500',
                holographic: true,
            };
        case 'epik':
            return {
                icon: 'mdi:star-four-points',
                ringClass: 'ring-accent-500 shadow-accent-300/40',
                chipClass: 'bg-accent-500 text-white ring-2 ring-white',
                bgClass: 'bg-gradient-to-br from-accent-100 via-accent-50 to-pop-50/60',
                cornerClass: 'from-accent-300 to-accent-500',
                holographic: false,
            };
        case 'langka':
            return {
                icon: 'mdi:star',
                ringClass: 'ring-mood-spinning shadow-mood-spinning/30',
                chipClass: 'bg-mood-spinning text-white ring-2 ring-white',
                bgClass: 'bg-gradient-to-br from-mood-spinning/20 via-surface-elev to-mood-spinning/10',
                cornerClass: 'from-mood-spinning/50 to-mood-spinning',
                holographic: false,
            };
        case 'jarang':
            return {
                icon: 'mdi:star-outline',
                ringClass: 'ring-brand-400 shadow-brand-200/40',
                chipClass: 'bg-brand-500 text-white ring-2 ring-white',
                bgClass: 'bg-gradient-to-br from-brand-100 via-surface-elev to-brand-50',
                cornerClass: 'from-brand-300 to-brand-500',
                holographic: false,
            };
        default:
            return {
                icon: 'mdi:circle-outline',
                ringClass: 'ring-line',
                chipClass: 'bg-ink-meta text-white ring-2 ring-white',
                bgClass: 'bg-surface-elev',
                cornerClass: 'from-line to-line',
                holographic: false,
            };
    }
}

export default function RunCard({ card, detail, className, size = 'normal' }: Readonly<RunCardProps>) {
    const r = rarityStyle(card.rarity);
    const km = detail.distance != null ? (detail.distance / 1000).toFixed(2) : '—';
    const duration = detail.moving_time != null ? formatDuration(detail.moving_time) : '—';
    const trimp = detail.trimp_edwards != null ? Math.round(detail.trimp_edwards) : '—';
    const isHero = size === 'hero';

    return (
        <article
            className={cn(
                'group relative flex h-full flex-col overflow-hidden rounded-3xl shadow-md ring-2 transition hover:shadow-xl',
                isHero ? 'p-7 ring-4 shadow-lg' : 'p-5 ring-2',
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
                    <p className="mt-0.5 truncate text-sm font-medium text-ink-soft">
                        {detail.name ?? 'Run'}
                    </p>
                    <p className="mt-0.5 text-xs text-ink-meta">
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
        <div>
            <div className={cn('font-black tabular-nums text-ink', valueClass)}>{value}</div>
            <div className="text-[10px] uppercase tracking-wide text-ink-meta">{unit}</div>
        </div>
    );
}
