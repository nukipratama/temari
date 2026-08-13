import { Icon } from '@iconify/react';
import { router } from '@inertiajs/react';
import { useEffect } from 'react';

const POLL_MS = 8000;
const MAX_POLLS = 30;

interface RunHydratingNoticeProps {
    /** False once the run's detail and streams have landed. */
    hydrating: boolean;
}

/**
 * A summary-only run carries distance, time, pace and average HR straight from
 * Strava's activity list, and nothing else: no splits, no laps, no heart-rate
 * zones, no effort score, no card. Opening the run queues the deeper fetch, so
 * the page is honest but momentarily thin. Say so, and reload when it lands
 * rather than leaving the reader to guess whether something broke.
 */
export default function RunHydratingNotice({
    hydrating,
}: Readonly<RunHydratingNoticeProps>) {
    useEffect(() => {
        if (!hydrating) {
            return;
        }

        let polls = 0;
        const timer = window.setInterval(() => {
            if (document.visibilityState !== 'visible') {
                return;
            }
            polls += 1;
            if (polls > MAX_POLLS) {
                window.clearInterval(timer);

                return;
            }
            router.reload();
        }, POLL_MS);

        return () => window.clearInterval(timer);
    }, [hydrating]);

    if (!hydrating) {
        return null;
    }

    return (
        <div
            role="status"
            className="mb-5 flex items-start gap-3 rounded-2xl border border-line bg-surface-sunken px-4 py-3"
        >
            <Icon
                icon="mdi:progress-download"
                width={20}
                height={20}
                className="mt-0.5 shrink-0 text-ink-3"
                aria-hidden
            />
            <div className="flex-1">
                <p className="font-sans text-sm font-semibold text-ink">
                    Still filling this run in
                </p>
                <p className="mt-1 font-sans text-sm leading-relaxed text-ink-2">
                    So far I have the distance, time and pace Strava lists for
                    it. The splits, heart-rate zones, effort score and its card
                    come from a second, deeper fetch, and that one is queued.
                    Nothing here is wrong, it is just not all here yet. This
                    page refreshes itself when the rest arrives.
                </p>
            </div>
        </div>
    );
}
