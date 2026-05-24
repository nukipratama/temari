import { type ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface StatTileProps {
    value: ReactNode;
    label: string;
    sub?: ReactNode;
    tone?: 'cream' | 'sky' | 'creamDeep';
    size?: 'sm' | 'md';
    className?: string;
}

const TONE_BG: Record<NonNullable<StatTileProps['tone']>, string> = {
    cream: 'bg-cream',
    sky: 'bg-cream/[0.06] border border-cream/[0.12]',
    creamDeep: 'bg-cream-deep',
};

export default function StatTile({
    value,
    label,
    sub,
    tone = 'cream',
    size = 'md',
    className,
}: Readonly<StatTileProps>) {
    const onSky = tone === 'sky';
    const labelClass = onSky ? 'text-cream/55' : 'text-ink-3';
    const valueClass = onSky ? 'text-cream' : 'text-ink';

    return (
        <div
            className={cn(
                'rounded-xl',
                size === 'sm' ? 'px-4 py-3.5' : 'px-[22px] py-[18px]',
                TONE_BG[tone],
                className,
            )}
        >
            <div className={cn('mb-2 font-mono text-[9px] uppercase tracking-[0.14em]', labelClass)}>
                {label}
            </div>
            <div
                className={cn(
                    'font-sans font-bold leading-none tracking-[-0.02em] tabular-nums',
                    size === 'sm' ? 'text-2xl' : 'text-[32px]',
                    valueClass,
                )}
            >
                {value}
            </div>
            {sub != null && (
                <div className={cn('mt-1.5 font-sans text-xs', labelClass)}>{sub}</div>
            )}
        </div>
    );
}
