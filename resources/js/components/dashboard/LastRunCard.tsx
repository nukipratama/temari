import { Link } from '@inertiajs/react';

import type { ActivityDetail } from '@/types/inertia';

import { Icon } from '@/components/ui/Icon';
import Card from '@/components/ui/LegacyCard';
import MiniRow from '@/components/ui/MiniRow';
import {
    formatKm,
    formatNaiveRelativeId,
    formatPace,
    paceSecPerKm,
} from '@/lib/pace';
import { activityUrl } from '@/lib/routes';

/**
 * The prototype's "last run · yesterday" mini card, one half of the pair at
 * the foot of Today's stats disclosure: three rows of the run's headline
 * numbers and a link out to its detail page.
 */
export default function LastRunCard({
    run,
}: Readonly<{ run: ActivityDetail }>) {
    const paceSec = paceSecPerKm(run.elapsed_time, run.distance);
    const trimp =
        run.trimp_edwards != null ? Math.round(run.trimp_edwards) : null;

    return (
        <Card padding="panel">
            <h4 className="mb-2 font-mono text-[0.625rem] font-extrabold uppercase tracking-[0.05em] text-foreground">
                Last run · {formatNaiveRelativeId(run.start_date_local)}
            </h4>
            <MiniRow label="km" value={formatKm(run.distance)} />
            <MiniRow
                label="pace"
                value={paceSec != null ? `${formatPace(paceSec)}/km` : '—'}
            />
            <MiniRow
                label="trimp"
                value={trimp != null ? String(trimp) : '—'}
            />
            <Link
                href={activityUrl(run)}
                className="focus-ring mt-2 inline-flex items-center gap-0.5 rounded text-[0.65625rem] text-foreground underline"
            >
                View run detail
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
