import { cn } from '@/lib/cn';
import { formatDuration } from '@/lib/pace';
import { BADGE_LABELS, RARITY_LABELS } from '@/lib/runcard';
import type { ActivityDetail, RunCard as RunCardModel } from '@/types/inertia';

interface RunCardProps {
    card: RunCardModel;
    detail: ActivityDetail;
    className?: string;
}

export default function RunCard({ card, detail, className }: Readonly<RunCardProps>) {
    const ringClass = rarityRing(card.rarity);
    const chipClass = rarityChip(card.rarity);
    const km = detail.distance != null ? (detail.distance / 1000).toFixed(2) : '—';
    const duration = detail.moving_time != null ? formatDuration(detail.moving_time) : '—';
    const trimp = detail.trimp_edwards != null ? Math.round(detail.trimp_edwards) : '—';

    return (
        <article className={cn('flex h-full flex-col rounded-3xl bg-surface-elev p-6 ring-4 dark:bg-surface-dark-elev', ringClass, className)}>
            <header className="flex items-start justify-between gap-3">
                <div>
                    <h3 className="text-lg font-black tracking-tight text-ink dark:text-ink-dark">{card.special_move}</h3>
                    <p className="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">{detail.name ?? 'Run'}</p>
                </div>
                <span className={cn('rounded-full px-2 py-1 text-[10px] font-bold uppercase tracking-widest', chipClass)}>
                    {RARITY_LABELS[card.rarity] ?? card.rarity}
                </span>
            </header>

            <div className="mt-5 grid grid-cols-3 gap-3 text-center">
                <Stat value={km} unit="km" />
                <Stat value={duration} unit="durasi" />
                <Stat value={trimp} unit="TRIMP" />
            </div>

            {card.badges && card.badges.length > 0 && (
                <ul className="mt-auto flex flex-wrap gap-2 pt-5">
                    {card.badges.map((b) => (
                        <li
                            key={b}
                            className="rounded-full bg-line/40 px-2 py-1 text-[11px] font-semibold text-ink dark:bg-line-dark dark:text-ink-dark"
                        >
                            {BADGE_LABELS[b] ?? b}
                        </li>
                    ))}
                </ul>
            )}
        </article>
    );
}

function Stat({ value, unit }: Readonly<{ value: string | number; unit: string }>) {
    return (
        <div>
            <div className="text-2xl font-black tabular-nums text-ink dark:text-ink-dark">{value}</div>
            <div className="text-[10px] uppercase tracking-wide text-ink-soft dark:text-ink-soft-dark">{unit}</div>
        </div>
    );
}

function rarityRing(rarity: string): string {
    switch (rarity) {
        case 'legendaris':
            return 'ring-pop-500';
        case 'epik':
            return 'ring-accent-500';
        case 'langka':
            return 'ring-mood-spinning';
        case 'jarang':
            return 'ring-mood-bouncy';
        default:
            return 'ring-line dark:ring-line-dark';
    }
}

function rarityChip(rarity: string): string {
    switch (rarity) {
        case 'legendaris':
            return 'bg-pop-500/15 text-pop-600 dark:text-pop-300';
        case 'epik':
            return 'bg-accent-500/15 text-accent-700 dark:text-accent-300';
        case 'langka':
            return 'bg-mood-spinning/15 text-mood-spinning';
        case 'jarang':
            return 'bg-mood-bouncy/15 text-mood-bouncy';
        default:
            return 'bg-line/40 text-ink-soft dark:bg-line-dark dark:text-ink-soft-dark';
    }
}
