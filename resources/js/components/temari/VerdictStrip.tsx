import { useState } from 'react';
import { Icon } from '@iconify/react';
import { cn } from '@/lib/cn';
import { formatIdDate } from '@/lib/pace';
import { pressShrink } from '@/lib/motion';
import MotionLink from '@/components/MotionLink';
import TemariMascot from './TemariMascot';
import DegradedChip from './DegradedChip';
import type { VerdictTimelineItem } from '@/types/inertia';

const VISIBLE_DEFAULT = 6;

interface VerdictStripProps {
    items: VerdictTimelineItem[];
}

/**
 * "Kata Temari" run verdict grid. Shows first 6 cards in a responsive
 * 3-col grid; remaining cards revealed with an expand toggle.
 */
export default function VerdictStrip({ items }: Readonly<VerdictStripProps>) {
    const [expanded, setExpanded] = useState(false);

    if (items.length === 0) return null;

    const visible = expanded ? items : items.slice(0, VISIBLE_DEFAULT);
    const hiddenCount = items.length - VISIBLE_DEFAULT;

    return (
        <div className="mt-3">
            <div className="flex justify-end">
                <span className="text-xs text-ink-meta dark:text-ink-meta-dark">{items.length} run terakhir</span>
            </div>

            <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {visible.map((item) => (
                    <MotionLink
                        key={item.activityId}
                        href={`/runs/${item.activityId}`}
                        whileTap={pressShrink}
                        className="group flex flex-col gap-2 rounded-2xl border border-line bg-surface-elev p-4 transition hover:border-brand-400/60 hover:shadow-sm dark:border-line-dark dark:bg-surface-dark-elev dark:hover:border-brand-500/40"
                    >
                        <div className="flex items-center gap-2">
                            <TemariMascot
                                mood={item.mood}
                                sizeClass="h-9 w-9 shrink-0"
                                sigilPixels={36}
                                ringClass="ring-2"
                            />
                            <div className="min-w-0 flex-1">
                                <div className="truncate text-xs font-semibold text-ink dark:text-ink-dark">
                                    {item.distanceKm.toFixed(1)} km
                                </div>
                                <div className="text-[10px] uppercase tracking-wider text-ink-meta dark:text-ink-meta-dark">
                                    {formatIdDate(item.startedAt)}
                                </div>
                            </div>
                            {item.degraded && <DegradedChip />}
                        </div>
                        <p className="line-clamp-2 text-xs leading-relaxed text-ink dark:text-ink-dark">{item.oneline}</p>
                    </MotionLink>
                ))}
            </div>

            {hiddenCount > 0 && (
                <button
                    type="button"
                    onClick={() => setExpanded((v) => !v)}
                    className={cn(
                        'mt-3 flex w-full items-center justify-center gap-1.5 rounded-xl py-2 text-xs font-semibold text-ink-meta transition',
                        'hover:bg-line/30 dark:text-ink-meta-dark dark:hover:bg-line-dark/40',
                    )}
                >
                    <Icon
                        icon="mdi:chevron-down"
                        width={14}
                        height={14}
                        className={cn('transition', expanded && 'rotate-180')}
                        aria-hidden
                    />
                    {expanded ? 'Sembunyikan' : `Lihat ${hiddenCount} lainnya`}
                </button>
            )}
        </div>
    );
}
