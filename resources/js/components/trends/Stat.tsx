import type { ReactNode } from 'react';

import { cn } from '@/lib/cn';

interface StatProps {
    label: string;
    value: string;
    /** Rendered next to the value — {@link StatDelta} or a plain string. */
    delta?: ReactNode;
    sub?: string;
    /** Default `lg`; `sm` is for a denser multi-tile row (three-up and up). */
    size?: 'lg' | 'sm';
    className?: string;
}

/** One labelled number — the shared shape every /trends comparison card
 *  states its evidence in. Every number keeps its own label. */
export function Stat({
    label,
    value,
    delta,
    sub,
    size = 'lg',
    className,
}: Readonly<StatProps>) {
    return (
        <div className={className}>
            <span className="text-label-micro text-text-3">{label}</span>
            <div className="mt-1 flex items-baseline gap-2">
                <span
                    className={cn(
                        size === 'lg' ? 'text-stat' : 'text-stat-sm',
                        'font-mono font-bold tabular-nums text-foreground',
                    )}
                >
                    {value}
                </span>
                {delta}
            </div>
            {sub !== undefined && (
                <p className="mt-1 text-xs leading-relaxed text-text-3">
                    {sub}
                </p>
            )}
        </div>
    );
}

/**
 * A signed change, colour-toned by direction — the pattern every stat that
 * carries a "vs before" reading uses (`+3.8`, `−1`, `±0`). Never used on its
 * own: it always sits beside the {@link Stat} it explains.
 */
export function StatDelta({
    value,
    unit = '',
    decimals = 1,
}: Readonly<{ value: number; unit?: string; decimals?: number }>) {
    const rounded = Number(value.toFixed(decimals));
    const tone =
        rounded > 0
            ? 'text-horizon-ink'
            : rounded < 0
              ? 'text-ember-ink'
              : 'text-text-3';
    const sign = rounded > 0 ? '+' : rounded < 0 ? '−' : '±';

    return (
        <span className={cn('font-mono text-xs font-bold tabular-nums', tone)}>
            {sign}
            {Math.abs(rounded)}
            {unit}
        </span>
    );
}
