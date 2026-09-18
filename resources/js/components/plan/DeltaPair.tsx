import type { DeltaDirection } from '@/lib/plan';

import { cn } from '@/lib/cn';

/**
 * One changed number, read as `old → new`: the old value dimmed and struck
 * through, the new one at the row's normal weight, with a real text arrow
 * (not a decorative icon) so the direction survives in the accessible name
 * and in a plain-text assertion the same way. The glyph itself points the
 * way the value actually moved, and its colour follows direction so up and
 * down never read the same.
 */
export function DeltaPair({
    from,
    to,
    direction,
    className,
}: Readonly<{
    from: string;
    to: string;
    direction: DeltaDirection;
    className?: string;
}>) {
    return (
        <span className={cn('inline', className)}>
            <span className="text-text-3 line-through decoration-text-3">
                {from}
            </span>{' '}
            <span
                className={
                    direction === 'up' ? 'text-leaf-ink' : 'text-ember-ink'
                }
            >
                {direction === 'up' ? '↑' : '↓'}
            </span>{' '}
            <span className="text-foreground">{to}</span>
        </span>
    );
}

/**
 * A session-type change, leading a delta line: `tempo → easy`. Neutral — a
 * category shift, not a quantity moving up or down.
 */
export function SessionTypeDelta({
    from,
    to,
}: Readonly<{ from: string; to: string }>) {
    return (
        <span className="inline">
            <span className="text-text-3 line-through decoration-text-3">
                {from}
            </span>{' '}
            <span className="text-text-2">{'→'}</span>{' '}
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
