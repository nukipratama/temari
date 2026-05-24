import { Icon } from '@iconify/react';
import { useEffect, useState } from 'react';
import MetricExplainer from '@/components/MetricExplainer';
import { cn } from '@/lib/cn';
import type { MetricKey } from '@/lib/metricGlossary';

export interface DetailStat {
    label: string;
    value: string;
    explainerKey?: MetricKey;
}

interface DetailTeknisCollapsibleProps {
    /** Stable id used for the localStorage open-state key — pass the week ISO date. */
    storageKey: string;
    stats: ReadonlyArray<DetailStat>;
    className?: string;
}

const STORAGE_PREFIX = 'aktivitas.detailTeknisOpen.';

/**
 * Per-week collapsible that hides the 9-column sport-science row by default.
 * Power users who care expand it once and the open state persists in
 * localStorage so they don't re-click every visit.
 */
export default function DetailTeknisCollapsible({
    storageKey,
    stats,
    className,
}: Readonly<DetailTeknisCollapsibleProps>) {
    const fullKey = STORAGE_PREFIX + storageKey;
    const [open, setOpen] = useState(false);
    const [hydrated, setHydrated] = useState(false);

    // Defer the localStorage read until after first paint so SSR / tests
    // without a window don't blow up. Open-state hydration arrives on the
    // next tick — acceptable since the closed-state is the safe default.
    useEffect(() => {
        try {
            const stored = window.localStorage.getItem(fullKey);
            if (stored === '1') setOpen(true);
        } catch {
            // Ignore — private browsing / blocked storage just means we
            // start closed every visit. Harmless.
        }
        setHydrated(true);
    }, [fullKey]);

    const toggle = () => {
        const next = !open;
        setOpen(next);
        if (!hydrated) return;
        try {
            window.localStorage.setItem(fullKey, next ? '1' : '0');
        } catch {
            // See above.
        }
    };

    return (
        <div className={cn('rounded-xl border border-line bg-surface-elev/80', className)}>
            <button
                type="button"
                onClick={toggle}
                aria-expanded={open}
                className="flex w-full min-h-[44px] items-center justify-between gap-3 px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider text-ink-3 transition hover:bg-surface-sunken/40"
            >
                <span>Detail teknis · TRIMP, CTL, ATL...</span>
                <Icon
                    icon={open ? 'mdi:chevron-up' : 'mdi:chevron-down'}
                    width={16}
                    height={16}
                    aria-hidden
                />
            </button>
            {open && (
                <dl className="grid grid-cols-2 gap-3 border-t border-line px-4 py-3 text-sm tabular-nums sm:grid-cols-3 lg:grid-cols-9">
                    {stats.map((stat) => (
                        <div key={stat.label} className="min-w-0">
                            <dt className="flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wider text-ink-3">
                                <span>{stat.label}</span>
                                {stat.explainerKey && <MetricExplainer metricKey={stat.explainerKey} size="xs" />}
                            </dt>
                            <dd className="mt-0.5 truncate font-semibold text-ink">{stat.value}</dd>
                        </div>
                    ))}
                </dl>
            )}
        </div>
    );
}
