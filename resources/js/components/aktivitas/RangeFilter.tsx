import { router } from '@inertiajs/react';
import { cn } from '@/lib/cn';

export const RANGE_OPTIONS = ['8w', '12w', '6m', '1y'] as const;
export type RangeFilterValue = (typeof RANGE_OPTIONS)[number];

const RANGE_LABEL: Record<RangeFilterValue, string> = {
    '8w': '8 minggu',
    '12w': '12 minggu',
    '6m': '6 bulan',
    '1y': '1 tahun',
};

interface RangeFilterProps {
    active: RangeFilterValue;
    /** Inertia partial-reload prop list — only re-fetches the data the page actually needs. */
    only?: string[];
    className?: string;
}

/**
 * Chip group that switches the Aktivitas page's range window. Bound to the
 * `?range=` query param; selecting a chip triggers an Inertia partial reload
 * of the listed props so we don't re-render unrelated chrome.
 */
export default function RangeFilter({
    active,
    only = ['runs', 'rangeFilter', 'rangeStart', 'weeklySnapshots', 'notes'],
    className,
}: Readonly<RangeFilterProps>) {
    const onSelect = (value: RangeFilterValue) => {
        if (value === active) return;
        router.get('/aktivitas', { range: value }, {
            preserveScroll: true,
            preserveState: true,
            only,
        });
    };

    return (
        <nav aria-label="Range filter" className={cn('flex flex-wrap items-center gap-1.5', className)}>
            <span className="mr-2 font-mono text-[10px] uppercase tracking-[0.14em] text-ink-3">
                Tampilkan
            </span>
            {RANGE_OPTIONS.map((value) => {
                const isActive = value === active;
                return (
                    <button
                        key={value}
                        type="button"
                        onClick={() => onSelect(value)}
                        aria-pressed={isActive}
                        className={cn(
                            'rounded-full px-3.5 py-1.5 text-xs font-medium transition',
                            isActive
                                ? 'bg-sky font-semibold text-cream'
                                : 'bg-sky/[0.06] text-ink-2 hover:bg-sky/[0.12]',
                        )}
                    >
                        {RANGE_LABEL[value]}
                    </button>
                );
            })}
        </nav>
    );
}
