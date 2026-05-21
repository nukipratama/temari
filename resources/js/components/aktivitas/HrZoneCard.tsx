export const HR_ZONES = ['Z1', 'Z2', 'Z3', 'Z4', 'Z5'] as const;

export const HR_ZONE_COLORS: Record<(typeof HR_ZONES)[number], string> = {
    Z1: '#5fb088',
    Z2: '#2f956a',
    Z3: '#d99a1a',
    Z4: '#c46f1c',
    Z5: '#b8302f',
};

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
                <h3 className="text-xs font-semibold uppercase tracking-wider text-ink-meta">HR Zones</h3>
                <p className="text-xs text-ink-meta">
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
                        <dt className="text-ink-meta">{zone}</dt>
                        <dd className="font-semibold text-ink">{zonePct[zone] ?? 0}%</dd>
                    </div>
                ))}
            </dl>
        </section>
    );
}
