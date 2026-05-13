import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import type { Tone } from '@/types/inertia';

interface KpiTileProps {
    label: string;
    value: ReactNode;
    sub?: ReactNode;
    tone?: Tone;
}

export default function KpiTile({ label, value, sub, tone = 'neutral' }: Readonly<KpiTileProps>) {
    const toneClass = toneToClass(tone);
    return (
        <div className="rounded-2xl border border-line bg-surface-elev p-4 dark:border-line-dark dark:bg-surface-dark-elev">
            <div className="text-xs font-semibold uppercase tracking-wider text-ink-meta dark:text-ink-meta-dark">
                {label}
            </div>
            <div className={cn('mt-2 text-3xl font-black tabular-nums', toneClass)}>{value}</div>
            {sub != null && (
                <div className="mt-1 text-xs text-ink-meta dark:text-ink-meta-dark">{sub}</div>
            )}
        </div>
    );
}

function toneToClass(tone: Tone): string {
    switch (tone) {
        case 'positive':
            return 'text-mood-bouncy';
        case 'warning':
            return 'text-mood-glow';
        case 'alert':
            return 'text-mood-cooked';
        default:
            return 'text-ink dark:text-ink-dark';
    }
}
