import type { DeltaDirection } from '@/lib/plan';

import { cn } from '@/lib/cn';

/** `DeltaPair`'s own direction, plus `neutral` for a category shift (a
 *  session-type change) rather than a quantity moving up or down. */
export type DeltaPairDirection = DeltaDirection | 'neutral';

const DIRECTION_GLYPH: Record<DeltaPairDirection, string> = {
    up: '↑',
    down: '↓',
    neutral: '→',
};

const DIRECTION_COLOR: Record<DeltaPairDirection, string> = {
    up: 'text-leaf-ink',
    down: 'text-ember-ink',
    neutral: 'text-text-2',
};

/**
 * One changed value, read as `old → new`: the old value dimmed and struck
 * through, the new one at the row's normal weight, with a real text arrow
 * (not a decorative icon) so the direction survives in the accessible name
 * and in a plain-text assertion the same way. The glyph itself points the
 * way the value actually moved, and its colour follows direction so up and
 * down never read the same; `neutral` (a session-type change) gets its own
 * plain arrow and colour instead.
 */
export function DeltaPair({
    from,
    to,
    direction,
    className,
}: Readonly<{
    from: string;
    to: string;
    direction: DeltaPairDirection;
    className?: string;
}>) {
    return (
        <span className={cn('inline', className)}>
            <span className="text-text-3 line-through decoration-text-3">
                {from}
            </span>{' '}
            <span className={DIRECTION_COLOR[direction]}>
                {DIRECTION_GLYPH[direction]}
            </span>{' '}
            <span className="text-foreground">{to}</span>
        </span>
    );
}

/**
 * The one mono tag a delta line may carry — only where the numbers alone
 * don't say why (`eased`, `week fit`). At most one per line.
 */
export function DeltaTag({ children }: Readonly<{ children: string }>) {
    return <span className="text-label-micro text-text-2">{children}</span>;
}

/**
 * One changed thing, labelled: `type`, `km` or `pace`, its delta pair, and
 * the tag naming why. Lives in a day's expanded panel — the collapsed row
 * shows only the current numbers and the tags themselves (see `DeltaTag`
 * usage at each call site), never the old value or the arrow.
 */
export function ChangeRow({
    label,
    from,
    to,
    direction,
    tag,
    className,
}: Readonly<{
    label: string;
    from: string;
    to: string;
    direction: DeltaPairDirection;
    tag: string;
    className?: string;
}>) {
    return (
        <p
            className={cn(
                'flex flex-wrap items-baseline gap-x-1.5 gap-y-0.5 text-xs',
                className,
            )}
        >
            <span className="text-label-micro inline-block min-w-10 text-text-3">
                {label}
            </span>
            <DeltaPair from={from} to={to} direction={direction} />
            <DeltaTag>{tag}</DeltaTag>
        </p>
    );
}

/**
 * A credited day, paired by side rather than by figure: what was asked
 * (distance and its effective pace together) and what was run (distance and
 * the pace over the credited runs). No arrows — the plan did not change,
 * this states what happened.
 */
export function AskedRanResult({
    askedKm,
    askedPace,
    ranKm,
    ranPace,
}: Readonly<{
    askedKm: number;
    askedPace: string | null;
    ranKm: number;
    ranPace: string | null;
}>) {
    const asked = [`asked ${askedKm} km`, askedPace]
        .filter((part): part is string => part !== null)
        .join(' · ');
    const ran = [`ran ${ranKm} km`, ranPace]
        .filter((part): part is string => part !== null)
        .join(' · ');

    return (
        <span className="mt-0.5 block text-xs text-text-2">
            <span className="block font-semibold text-foreground">{asked}</span>
            <span className="block font-semibold text-foreground">{ran}</span>
        </span>
    );
}
