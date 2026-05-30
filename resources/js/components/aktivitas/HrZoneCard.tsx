import MetricExplainer from '@/components/MetricExplainer';
import { hrZone } from '@/lib/chartTokens';
import type { MetricKey } from '@/lib/metricGlossary';

export const HR_ZONES = ['Z1', 'Z2', 'Z3', 'Z4', 'Z5'] as const;

const ZONE_EXPLAINER_KEY: Record<(typeof HR_ZONES)[number], MetricKey> = {
    Z1: 'hr_z1',
    Z2: 'hr_z2',
    Z3: 'hr_z3',
    Z4: 'hr_z4',
    Z5: 'hr_z5',
};

export const HR_ZONE_COLORS: Record<(typeof HR_ZONES)[number], string> = hrZone;

export interface ZonePct {
    Z1?: number;
    Z2?: number;
    Z3?: number;
    Z4?: number;
    Z5?: number;
}

interface HrZoneCardProps {
    zonePct: ZonePct;
}

export default function HrZoneCard({ zonePct }: Readonly<HrZoneCardProps>) {
    const dominant = HR_ZONES.reduce<{ zone: (typeof HR_ZONES)[number]; pct: number }>(
        (acc, zone) => {
            const pct = Number(zonePct[zone] ?? 0);
            return pct > acc.pct ? { zone, pct } : acc;
        },
        { zone: 'Z1', pct: 0 },
    );

    return (
        <section className="rounded-2xl border border-line bg-surface-elev p-4 shadow-sm sm:p-5">
            <div className="flex items-baseline justify-between gap-3">
                <h3 className="flex items-center gap-1 font-mono text-xs font-semibold uppercase tracking-wider text-ink-3">
                    HR Zones
                    <MetricExplainer metricKey="hr_zones" size="xs" />
                </h3>
                <p className="text-xs text-ink-3">
                    dominan{' '}
                    <span className="font-bold tabular-nums" style={{ color: HR_ZONE_COLORS[dominant.zone] }}>
                        {dominant.zone} · {dominant.pct}%
                    </span>
                </p>
            </div>
            <div className="mt-3 flex h-3 overflow-hidden rounded-full">
                {HR_ZONES.map((zone) => {
                    const width = Number(zonePct[zone] ?? 0);
                    if (width <= 0) return null;
                    return (
                        <div
                            key={zone}
                            style={{ width: `${width}%`, background: HR_ZONE_COLORS[zone] }}
                            title={`${zone}: ${width}%`}
                        />
                    );
                })}
            </div>
            <dl className="mt-3 grid grid-cols-5 gap-2 text-xs tabular-nums">
                {HR_ZONES.map((zone) => (
                    <div key={zone} className="text-center">
                        <dt className="flex items-center justify-center gap-0.5 text-ink-3">
                            {zone}
                            <MetricExplainer metricKey={ZONE_EXPLAINER_KEY[zone]} size="xs" />
                        </dt>
                        <dd className="font-semibold text-ink">{zonePct[zone] ?? 0}%</dd>
                    </div>
                ))}
            </dl>
        </section>
    );
}
