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
    const labelClass = onSky ? 'text-ink-on-sky' : 'text-ink-2';
    const subClass = onSky ? 'text-ink-on-sky' : 'text-ink-3';
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
            <div className={cn('mb-2 text-label-small', labelClass)}>
                {label}
            </div>
            <div
                className={cn(
                    'leading-none',
                    size === 'sm' ? 'text-stat-sm' : 'text-stat',
                    valueClass,
                )}
            >
                {value}
            </div>
            {sub != null && (
                <div className={cn('mt-1.5 font-sans text-xs', subClass)}>{sub}</div>
            )}
        </div>
    );
}
