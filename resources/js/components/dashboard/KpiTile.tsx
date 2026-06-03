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
    positive: 'text-mood-enteng',
    warning: 'text-mood-nyala',
    alert: 'text-mood-lemes',
    neutral: 'text-ink',
};

export default function KpiTile({ label, value, sub, tone = 'neutral', explainerKey }: Readonly<KpiTileProps>) {
    return (
        <div className="rounded-2xl border border-line bg-surface-card p-3 sm:p-4">
            <div className="flex items-center gap-1 font-mono text-[12px] font-bold uppercase tracking-wider text-ink-2 sm:text-xs">
                <span>{label}</span>
                {explainerKey && <MetricExplainer metricKey={explainerKey} size="xs" />}
            </div>
            <div className={cn('mt-1.5 font-mono text-2xl font-bold tabular-nums sm:mt-2 sm:text-3xl', TONE_CLASS[tone])}>{value}</div>
            {sub != null && (
                <div className="mt-1 text-xs text-ink-3">{sub}</div>
            )}
        </div>
    );
}
