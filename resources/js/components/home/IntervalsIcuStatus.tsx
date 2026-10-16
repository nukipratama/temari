import { CircleAlert, Watch } from 'lucide-react';

import type { IntervalsIcuSyncStatus } from '@/types/generated';

import Chip from '@/components/ui/Chip';
import { Icon } from '@/components/ui/Icon';

/** Where today's workout stands on intervals.icu, `broken` when the key stopped working. */
export type TodayIntervalsIcu = IntervalsIcuSyncStatus | 'broken';

const REASON: Record<Exclude<TodayIntervalsIcu, 'sent'>, string> = {
    no_threshold_hr:
        'not sent to intervals.icu: set your threshold heart rate there first.',
    unreachable: "couldn't reach intervals.icu. trying again.",
    broken: 'intervals.icu stopped accepting your key. reconnect it in settings.',
};

/** The chip beside today's session: sent, or not sent with {@link intervalsIcuReason} beneath. */
export function IntervalsIcuChip({
    status,
}: Readonly<{ status: TodayIntervalsIcu }>) {
    const sent = status === 'sent';

    return (
        <Chip
            tone={sent ? 'positive' : 'warning'}
            className="inline-flex items-center gap-1"
        >
            <Icon
                icon={sent ? Watch : CircleAlert}
                width={12}
                aria-hidden="true"
            />
            {sent ? 'on intervals.icu' : 'not sent'}
        </Chip>
    );
}

export function intervalsIcuReason(status: TodayIntervalsIcu): string | null {
    return status === 'sent' ? null : REASON[status];
}
