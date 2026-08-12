import { Link } from '@inertiajs/react';

import type { TrainingLoad, WeeklySnapshot } from '@/types/inertia';

import Card from '@/components/ui/Card';
import SectionLabel from '@/components/ui/SectionLabel';
import { cn } from '@/lib/cn';
import {
    atlHint,
    atlTone,
    ctlHint,
    monotonyHint,
    monotonyTone,
    strainHint,
    strainTone,
} from '@/pages/Today/helpers';

export default function KondisiCard({
    load,
    snapshot,
    onSky = false,
}: Readonly<{
    load: TrainingLoad | null;
    snapshot: WeeklySnapshot | null;
    /** Cream-on-dark treatment for use on a HeroPanel/sky background. */
    onSky?: boolean;
}>) {
    const rows: ReadonlyArray<{
        label: string;
        value: string;
        hint: string;
        color: string;
    }> = [
        {
            label: 'Fitness',
            value: load?.ctl_42d != null ? load.ctl_42d.toFixed(1) : '—',
            hint: ctlHint(load?.ctl_42d),
            color: 'text-leaf',
        },
        {
            label: 'Fatigue',
            value: load?.atl_7d != null ? load.atl_7d.toFixed(1) : '—',
            hint: atlHint(load?.atl_7d),
            color: atlTone(load?.atl_7d),
        },
        {
            label: 'Strain',
            value:
                load?.strain != null ? Math.round(load.strain).toString() : '—',
            hint: strainHint(load?.strain),
            color: strainTone(load?.strain),
        },
        {
            label: 'Monotony',
            value: load?.monotony != null ? load.monotony.toFixed(2) : '—',
            hint: monotonyHint(load?.monotony),
            color: monotonyTone(load?.monotony),
        },
    ];
    return (
        <Card
            as="section"
            tone={onSky ? 'sky-glass' : 'cream'}
            padding="md"
            className="flex h-full flex-col gap-3"
        >
            <SectionLabel dot onSky={onSky} className="mb-0">
                Condition · {snapshot ? '7 days' : 'not enough data yet'}
            </SectionLabel>
            {rows.map(({ label, value, hint, color }) => (
                <div
                    key={label}
                    className={cn(
                        'flex items-baseline justify-between py-1.5 border-b last:border-b-0',
                        onSky ? 'border-cream/15' : 'border-cream-deep',
                    )}
                >
                    <div>
                        <div
                            className={cn(
                                'text-[13px] font-medium',
                                onSky ? 'text-cream' : 'text-ink',
                            )}
                        >
                            {label}
                        </div>
                        <div
                            className={cn(
                                'font-display text-xs italic',
                                onSky ? 'text-ink-on-sky' : 'text-ink-3',
                            )}
                        >
                            {hint}
                        </div>
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
                href="/activities"
                className={cn(
                    'focus-ring mt-auto rounded pt-1 text-label-micro',
                    onSky
                        ? 'text-horizon hover:text-cream'
                        : 'text-horizon-deep hover:text-ember-deep',
                )}
            >
                Technical detail →
            </Link>
        </Card>
    );
}
