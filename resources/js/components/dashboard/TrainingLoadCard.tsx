import { Link } from '@inertiajs/react';

import type { TrainingLoad, WeeklySnapshot } from '@/types/inertia';

import Card from '@/components/ui/LegacyCard';
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
} from '@/pages/Home/helpers';

const NO_HR_HINT = 'no HR on these runs';

export default function TrainingLoadCard({
    load,
    snapshot,
    onSky = false,
}: Readonly<{
    load: TrainingLoad | null;
    snapshot: WeeklySnapshot | null;
    /** Cream-on-dark treatment for use on a HeroPanel/sky background. */
    onSky?: boolean;
}>) {
    // A run with no heart-rate stream scores no TRIMP, so strain and monotony
    // come back unknown rather than zero. Say which it is.
    const noHr = load !== null && load.strain === null ? NO_HR_HINT : '';
    let scope = 'not enough data yet';
    if (snapshot !== null) {
        scope = load === null ? 'no HR data yet' : '7 days';
    }
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
            color: 'text-leaf-ink',
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
            hint: noHr === '' ? strainHint(load?.strain) : noHr,
            color: strainTone(load?.strain),
        },
        {
            label: 'Monotony',
            value: load?.monotony != null ? load.monotony.toFixed(2) : '—',
            hint: noHr === '' ? monotonyHint(load?.monotony) : noHr,
            color: monotonyTone(load?.monotony),
        },
    ];
    return (
        <Card
            as="section"
            tone={onSky ? 'onSky' : 'card'}
            padding="panel"
            className="flex h-full flex-col gap-3"
        >
            <SectionLabel dot onSky={onSky} className="mb-0">
                Condition · {scope}
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
                                onSky ? 'text-cream' : 'text-foreground',
                            )}
                        >
                            {label}
                        </div>
                        <div
                            className={cn(
                                'font-serif text-xs italic',
                                onSky ? 'text-ink-on-sky' : 'text-text-3',
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
                href="/history"
                className={cn(
                    'focus-ring mt-auto inline-flex min-h-6 items-center rounded pt-1 text-label-micro',
                    onSky
                        ? 'text-horizon hover:text-cream'
                        : 'text-horizon-ink hover:text-ember-ink',
                )}
            >
                Technical detail →
            </Link>
        </Card>
    );
}
