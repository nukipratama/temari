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
        <nav aria-label="Range filter" className={cn('flex flex-wrap gap-2', className)}>
            <span className="mr-1 self-center text-xs font-semibold uppercase tracking-wider text-ink-meta">
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
                            'rounded-full border px-3 py-1 text-sm font-medium transition',
                            isActive
                                ? 'border-brand-500 bg-brand-500 text-white shadow-sm'
                                : 'border-line bg-surface-elev text-ink-soft hover:border-brand-300 hover:text-ink',
                        )}
                    >
                        {RANGE_LABEL[value]}
                    </button>
                );
            })}
        </nav>
    );
}
