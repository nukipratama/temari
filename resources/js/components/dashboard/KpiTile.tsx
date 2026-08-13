import type { ReactNode } from 'react';

import type { MetricKey } from '@/lib/metricGlossary';
import type { Tone } from '@/types/inertia';

import MetricExplainer from '@/components/MetricExplainer';
import { cn } from '@/lib/cn';

interface KpiTileProps {
    label: string;
    value: ReactNode;
    sub?: ReactNode;
    tone?: Tone;
    /** When set, renders a `(?)` button next to the label that opens a metric glossary popover. */
    explainerKey?: MetricKey;
    /** Cream-on-dark treatment for use on a HeroPanel/sky background. */
    onSky?: boolean;
}

const TONE_CLASS: Record<Tone, string> = {
    positive: 'text-mood-easy',
    warning: 'text-mood-blazing',
    alert: 'text-mood-gassed',
    neutral: 'text-ink',
};

const TONE_CLASS_ON_SKY: Record<Tone, string> = {
    positive: 'text-mood-easy',
    warning: 'text-mood-blazing',
    alert: 'text-mood-gassed',
    neutral: 'text-cream',
};

export default function KpiTile({
    label,
    value,
    sub,
    tone = 'neutral',
    explainerKey,
    onSky = false,
}: Readonly<KpiTileProps>) {
    return (
        <div
            className={cn(
                'rounded-lg border p-3 shadow-e1 sm:px-4 sm:py-3.5',
                onSky
                    ? 'border-cream/[0.12] bg-cream/[0.06]'
                    : 'border-line bg-surface-card',
            )}
        >
            <div
                className={cn(
                    'flex items-center gap-1 font-mono text-[12px] font-bold uppercase tracking-wider sm:text-xs',
                    onSky ? 'text-ink-on-sky' : 'text-ink-2',
                )}
            >
                <span>{label}</span>
                {explainerKey && (
                    <MetricExplainer metricKey={explainerKey} size="xs" />
                )}
            </div>
            <div
                className={cn(
                    'mt-1.5 font-mono text-2xl font-bold tabular-nums sm:mt-2 sm:text-3xl',
                    onSky ? TONE_CLASS_ON_SKY[tone] : TONE_CLASS[tone],
                )}
            >
                {value}
            </div>
            {sub != null && (
                <div
                    className={cn(
                        'mt-1 text-xs',
                        onSky ? 'text-ink-on-sky' : 'text-ink-3',
                    )}
                >
                    {sub}
                </div>
            )}
        </div>
    );
}
