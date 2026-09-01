import { Link } from '@inertiajs/react';

import type { TrainingLoad, WeeklySnapshot } from '@/types/inertia';

import { Icon } from '@/components/ui/Icon';
import Card from '@/components/ui/LegacyCard';
import MiniRow from '@/components/ui/MiniRow';

/**
 * The prototype's "condition · 7 days" mini card, the other half of the pair
 * at the foot of Today's stats disclosure: fitness, fatigue and strain, with
 * a link out to the full read-out. Monotony is not on the prototype's card;
 * it survives as History's per-week alert.
 */
export default function TrainingLoadCard({
    load,
    snapshot,
}: Readonly<{
    load: TrainingLoad | null;
    snapshot: WeeklySnapshot | null;
}>) {
    let scope = 'not enough data yet';
    if (snapshot !== null) {
        scope = load === null ? 'no HR data yet' : '7 days';
    }

    return (
        <Card padding="panel">
            <h4 className="mb-2 font-mono text-[10px] font-extrabold uppercase tracking-[0.05em] text-foreground">
                Condition · {scope}
            </h4>
            <MiniRow
                label="fitness"
                value={load?.ctl_42d != null ? load.ctl_42d.toFixed(1) : '—'}
            />
            <MiniRow
                label="fatigue"
                value={load?.atl_7d != null ? load.atl_7d.toFixed(1) : '—'}
            />
            <MiniRow
                label="strain"
                value={
                    load?.strain != null
                        ? Math.round(load.strain).toString()
                        : '—'
                }
            />
            <Link
                href="/history"
                className="focus-ring mt-2 inline-flex items-center gap-0.5 rounded text-[10.5px] text-foreground underline"
            >
                Technical detail
                <Icon
                    icon="mdi:arrow-right"
                    width={12}
                    height={12}
                    aria-hidden
                />
            </Link>
        </Card>
    );
}
