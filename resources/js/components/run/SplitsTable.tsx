import Card from '@/components/ui/Card';
import SectionLabel from '@/components/ui/SectionLabel';
import { cn } from '@/lib/cn';
import { formatKm, formatPace } from '@/lib/pace';
import { computeBarWidth, paceSecOf } from '@/lib/splits';
import type { StreamSummaryPartial, StreamSummaryPerKm } from '@/types/inertia';

export default function SplitsTable({
    rows,
    partial,
    className,
}: Readonly<{ rows: StreamSummaryPerKm[]; partial?: StreamSummaryPartial | null; className?: string }>) {
    const paces = rows
        .map((r) => paceSecOf(r))
        .filter((s): s is number => s != null && Number.isFinite(s));
    const fastest = paces.length > 0 ? Math.min(...paces) : null;
    const fastestKm = fastest != null ? rows.find((r) => paceSecOf(r) === fastest)?.km ?? null : null;
    const slowestSec = paces.length > 0 ? Math.max(...paces) : null;

    // Stable, collision-proof keys baked here — km for full splits, positional for
    // the trailing partial — so the render map keys off a data field, not its index.
    const keyedRows = rows.map((row, i) => ({
        row,
        key: row.km != null ? `km-${row.km}` : `partial-${i}`,
    }));

    return (
        <Card as="section" padding="lg" className={className}>
            <header className="mb-1.5 flex flex-wrap items-baseline justify-between gap-3">
                <SectionLabel>Splits per km</SectionLabel>
                {fastest != null && fastestKm != null && (
                    <p className="font-display text-sm italic text-ink-2">
                        Paling kenceng di km {fastestKm},{' '}
                        <span className="font-semibold text-horizon-deep">{formatPace(fastest)}/km</span>
                    </p>
                )}
            </header>
            {/* One dense chart at every width (HR + cadence columns fold away on phones);
                the binary bar color needs a one-line key once the card affordance is gone. */}
            <p className="mb-3 text-label-micro text-ink-3">
                Batang oranye = km tercepat, gelap = lainnya{partial ? ', putus-putus = sisa' : ''}.
            </p>

            <div className="flex flex-col gap-1">
                {keyedRows.map(({ row, key }, idx) => {
                    const sec = paceSecOf(row);
                    const isFast = sec != null && sec === fastest;
                    const pctWidth = computeBarWidth(sec, fastest, slowestSec);
                    const rowFill = splitRowFill(isFast, idx);
                    return (
                        <div
                            key={key}
                            className={cn(
                                'grid grid-cols-[34px_1fr_56px] items-center gap-2.5 lg:grid-cols-[40px_1fr_70px_70px_70px] lg:gap-3',
                                // Every row gets the same rounded background box + -mx-3/px-3
                                // bleed-and-realign so the fast row's alignment isn't special —
                                // only the bar color should differ (see computeBarWidth caller).
                                '-mx-3 rounded-lg px-3 py-2 lg:py-2.5',
                                rowFill,
                            )}
                        >
                            <div className="font-mono text-[11px] uppercase tracking-[0.1em] text-ink-2">
                                KM {row.km ?? '?'}
                            </div>
                            <div className="h-2.5 overflow-hidden rounded bg-sky/[0.06] lg:h-3">
                                <div
                                    className={cn('h-full rounded', isFast ? 'bg-horizon' : 'bg-sky')}
                                    style={{ width: `${pctWidth}%` }}
                                />
                            </div>
                            <div className="text-right font-sans text-sm font-semibold tabular-nums text-ink">
                                {row.pace ?? '—'}
                            </div>
                            <div className="hidden text-right font-sans text-xs tabular-nums text-ink-2 lg:block">
                                ♡ {row.avg_hr ?? '—'}
                            </div>
                            <div className="hidden text-right font-sans text-xs tabular-nums text-ink-2 lg:block">
                                ↻ {row.avg_cadence_spm ?? '—'}
                            </div>
                        </div>
                    );
                })}
                {partial && <SplitPartialRow partial={partial} />}
            </div>
        </Card>
    );
}

// The trailing "sisa" segment (e.g. the last 0.7 km of a 5.7 km run). Rendered
// muted with a dashed, empty (non-comparative) bar and detached by a hairline so
// a glance reads "outside the scale, not ranked against the full kms" — its
// normalized pace must never be compared head-to-head with a full km.
function SplitPartialRow({ partial }: Readonly<{ partial: StreamSummaryPartial }>) {
    return (
        <div className="-mx-3 mt-1 grid grid-cols-[34px_1fr_56px] items-center gap-2.5 rounded-lg border-t border-cream-deep px-3 py-2 lg:grid-cols-[40px_1fr_70px_70px_70px] lg:gap-3 lg:py-2.5">
            <div className="font-mono text-[11px] uppercase tracking-[0.02em] text-ink-3">
                {formatKm(partial.distance_m, 1)} KM
            </div>
            <div className="h-2.5 rounded border border-dashed border-sky/20 bg-sky/[0.06] lg:h-3" />
            <div className="text-right font-sans text-sm font-semibold tabular-nums text-ink-3">
                {partial.pace ?? '—'}
            </div>
            <div className="hidden text-right font-sans text-xs tabular-nums text-ink-3 lg:block">
                ♡ {partial.avg_hr ?? '—'}
            </div>
            <div className="hidden text-right font-sans text-xs tabular-nums text-ink-3 lg:block">
                ↻ {partial.avg_cadence_spm ?? '—'}
            </div>
        </div>
    );
}

// Every splits row shares the same rounded box (see SplitsTable); only this
// fill differs — horizon tint for the fastest km, a faint zebra stripe otherwise.
function splitRowFill(isFast: boolean, idx: number): string {
    if (isFast) return 'bg-horizon/[0.08]';
    if (idx % 2 === 1) return 'bg-cream-deep/30';
    return 'bg-sky/[0.03]';
}
