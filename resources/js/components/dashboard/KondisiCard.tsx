import { Link } from '@inertiajs/react';
import Card from '@/components/ui/Card';
import SectionLabel from '@/components/ui/SectionLabel';
import { cn } from '@/lib/cn';
import { atlHint, ctlHint, monotonyHint, strainHint } from '@/pages/HariIni/helpers';
import type { TrainingLoad, WeeklySnapshot } from '@/types/inertia';

export default function KondisiCard({
    load,
    snapshot,
}: Readonly<{ load: TrainingLoad | null; snapshot: WeeklySnapshot | null }>) {
    const rows: ReadonlyArray<{ label: string; value: string; hint: string; color: string }> = [
        {
            label: 'Fondasi',
            value: load?.ctl_42d != null ? load.ctl_42d.toFixed(1) : '—',
            hint: ctlHint(load?.ctl_42d),
            color: 'text-leaf',
        },
        {
            label: 'Kelelahan',
            value: load?.atl_7d != null ? load.atl_7d.toFixed(1) : '—',
            hint: atlHint(load?.atl_7d),
            color: 'text-ink-2',
        },
        {
            label: 'Beban',
            value: load?.strain != null ? Math.round(load.strain).toString() : '—',
            hint: strainHint(load?.strain),
            color: 'text-horizon',
        },
        {
            label: 'Variasi',
            value: load?.monotony != null ? load.monotony.toFixed(2) : '—',
            hint: monotonyHint(load?.monotony),
            color: 'text-leaf',
        },
    ];
    return (
        <Card as="section" padding="md" className="flex h-full flex-col gap-3">
            <SectionLabel dot className="mb-0">Kondisi · {snapshot ? '7 hari' : 'belum cukup data'}</SectionLabel>
            {rows.map(({ label, value, hint, color }) => (
                <div
                    key={label}
                    className="flex items-baseline justify-between py-1.5 border-b border-cream-deep last:border-b-0"
                >
                    <div>
                        <div className="text-[13px] font-medium text-ink">{label}</div>
                        <div className="font-display text-xs italic text-ink-3">{hint}</div>
                    </div>
                    <div
                        className={cn(
                            'font-sans text-2xl font-bold leading-none tabular-nums tracking-[-0.01em]',
                            color,
                        )}
                    >
                        {value}
                    </div>
                </div>
            ))}
            <Link
                href="/aktivitas"
                className="focus-ring mt-auto rounded pt-1 font-mono text-[11px] font-semibold uppercase tracking-[0.12em] text-horizon-deep hover:text-ember-deep"
            >
                Detail teknis →
            </Link>
        </Card>
    );
}
