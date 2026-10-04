import type { CSSProperties, ReactNode } from 'react';

import { Icon, type IconComponent } from '@/components/ui/Icon';
import { cn } from '@/lib/cn';

type StatSize = 'lg' | 'sm' | 'tile';

const VALUE_SIZE: Record<StatSize, string> = {
    lg: 'text-stat',
    sm: 'text-stat-sm',
    tile: 'text-stat-tile-fit md:text-stat-tile',
};

interface StatProps {
    /** Usually a plain string; a jargon label pairs it with an inline
     *  MetricExplainer instead. */
    label: ReactNode;
    value: string;
    /** Rendered next to the value — {@link StatDelta} or a plain string. */
    delta?: ReactNode;
    sub?: string;
    /** Default `lg`; `sm` for a secondary stat line; `tile` is StatTile's own step. */
    size?: StatSize;
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
            <div
                className={cn(
                    'mt-1 flex flex-wrap items-baseline gap-x-2',
                    size === 'tile' && '@container',
                )}
            >
                <span
                    className={cn(
                        VALUE_SIZE[size],
                        'font-mono font-bold whitespace-nowrap tabular-nums text-foreground',
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

interface StatTileProps extends Omit<StatProps, 'className' | 'size'> {
    /** Drawn before the label. */
    icon?: IconComponent;
    as?: 'div' | 'li';
    /** Extra rows under the number. */
    children?: ReactNode;
    id?: string;
    style?: CSSProperties;
    className?: string;
    /** Spans the label, value and sub rows of the parent grid, so sibling tiles share one row per part. */
    subgrid?: boolean;
}

/** MASTER's stat tile: a secondary-filled block holding one eyebrow and one number. */
export default function StatTile({
    label,
    icon,
    as: Tag = 'div',
    children,
    id,
    style,
    className,
    subgrid = false,
    ...stat
}: Readonly<StatTileProps>) {
    return (
        <Tag
            id={id}
            style={style}
            className={cn(
                'rounded-sm bg-secondary pad-panel',
                subgrid && 'row-span-3 grid grid-rows-subgrid gap-y-0',
                className,
            )}
        >
            <Stat
                size="tile"
                className={subgrid ? 'contents' : undefined}
                label={
                    icon ? (
                        <span className="inline-flex items-center gap-1.5">
                            <Icon
                                icon={icon}
                                width={12}
                                height={12}
                                aria-hidden
                                className="flex-none text-icon-accent"
                            />
                            {label}
                        </span>
                    ) : (
                        label
                    )
                }
                {...stat}
            />
            {children}
        </Tag>
    );
}
