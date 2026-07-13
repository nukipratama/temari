import { cn } from '@/lib/cn';
import { HR_ZONES, HR_ZONE_COLORS } from '@/lib/chartTokens';
import type { ZonePct } from '@/types/inertia';

interface ZoneBarProps {
    zonePct: ZonePct;
    /** Show tiny Z1..Z5 labels under the bar (full-tier card). Off = bare bar (md). */
    showLegend?: boolean;
    className?: string;
}

function formatZoneShare(zone: string, pct: number): string {
    return `${zone} ${pct}%`;
}

/**
 * A thin HR-zone effort bar for the card stat block: a stacked Z1..Z5 strip
 * using the shared `HR_ZONE_COLORS`, reading as an "energy gauge": bare on `md`,
 * with tiny labels on the full tier.
 * Renders nothing when the run has no zone data.
 */
export default function ZoneBar({ zonePct, showLegend = false, className }: Readonly<ZoneBarProps>) {
    const segments = HR_ZONES.map((zone) => ({ zone, pct: Number(zonePct[zone] ?? 0) })).filter((s) => s.pct > 0);
    if (segments.length === 0) return null;

    const dominant = segments.reduce((max, s) => (s.pct > max.pct ? s : max), segments[0]);
    const breakdown = segments.map((s) => formatZoneShare(s.zone, s.pct)).join(', ');
    const summary = `Distribusi zona detak jantung, didominasi ${dominant.zone} (${dominant.pct}%): ${breakdown}`;

    return (
        <div className={cn('flex flex-col gap-1', className)}>
            <div className="flex h-3 gap-0.5 overflow-hidden rounded-full bg-cream/10" aria-hidden>
                {segments.map(({ zone, pct }) => (
                    <div key={zone} className="min-w-[4px]" style={{ width: `${pct}%`, background: HR_ZONE_COLORS[zone] }} title={`${zone}: ${pct}%`} />
                ))}
            </div>
            <span className="sr-only">{summary}</span>
            {showLegend && (
                <div className="flex justify-between font-mono text-[8px] uppercase tracking-[0.08em] text-ink-on-sky">
                    {HR_ZONES.map((zone) => (
                        <span key={zone} className="flex min-w-[24px] items-center gap-0.5">
                            <span aria-hidden className="h-1.5 w-1.5 rounded-full" style={{ background: HR_ZONE_COLORS[zone] }} />
                            {zone}
                        </span>
                    ))}
                </div>
            )}
        </div>
    );
}
