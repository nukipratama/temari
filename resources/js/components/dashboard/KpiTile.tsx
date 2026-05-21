import type { ReactNode } from 'react';
import MetricExplainer from '@/components/MetricExplainer';
import { cn } from '@/lib/cn';
import type { MetricKey } from '@/lib/metricGlossary';
import type { Tone } from '@/types/inertia';

interface KpiTileProps {
    label: string;
    value: ReactNode;
    sub?: ReactNode;
    tone?: Tone;
    /** When set, renders a `(?)` button next to the label that opens a metric glossary popover. */
    explainerKey?: MetricKey;
}

const TONE_CLASS: Record<Tone, string> = {
    positive: 'text-mood-bouncy',
    warning: 'text-mood-glow',
    alert: 'text-mood-cooked',
    neutral: 'text-ink dark:text-ink-dark',
};

export default function KpiTile({ label, value, sub, tone = 'neutral', explainerKey }: Readonly<KpiTileProps>) {
    return (
        <div className="rounded-2xl border border-line bg-surface-elev p-3 dark:border-line-dark dark:bg-surface-dark-elev sm:p-4">
            <div className="flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wider text-ink-meta dark:text-ink-meta-dark sm:text-xs">
                <span>{label}</span>
                {explainerKey && <MetricExplainer metricKey={explainerKey} size="xs" />}
            </div>
            <div className={cn('mt-1.5 text-2xl font-black tabular-nums sm:mt-2 sm:text-3xl', TONE_CLASS[tone])}>{value}</div>
            {sub != null && (
                <div className="mt-1 text-xs text-ink-meta dark:text-ink-meta-dark">{sub}</div>
            )}
        </div>
    );
}
