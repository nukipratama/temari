import { Icon } from '@iconify/react';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import PillButton from '@/components/ui/PillButton';

const POLL_MS = 8000;
const MAX_POLLS = 30;

interface RunHydratingNoticeProps {
    /** False once the run's detail and streams have landed. */
    hydrating: boolean;
}

/**
 * A summary-only run carries distance, time, pace and average HR straight from
 * Strava's activity list, and nothing else: no splits, no laps, no heart-rate
 * zones, no effort score, no card. Opening the run queues the deeper fetch at
 * background priority, behind whatever live ingest is running, so the page is
 * honest but momentarily thin. Say so, and stop claiming a self-refresh once
 * the poll budget is spent rather than leaving a promise nothing will keep.
 */
export default function RunHydratingNotice({
    hydrating,
}: Readonly<RunHydratingNoticeProps>) {
    const [stoppedPolling, setStoppedPolling] = useState(false);

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
                setStoppedPolling(true);

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
            className="mb-5 flex items-start gap-3 rounded-lg border border-line bg-surface-sunken px-4 py-3"
        >
            <Icon
                icon={
                    stoppedPolling
                        ? 'mdi:clock-outline'
                        : 'mdi:progress-download'
                }
                width={20}
                height={20}
                className="mt-0.5 shrink-0 text-ink-3"
                aria-hidden
            />
            <div className="flex-1">
                <p className="font-sans text-sm font-semibold text-ink">
                    {stoppedPolling
                        ? 'Still waiting on the rest of this run'
                        : 'Still filling this run in'}
                </p>
                <p className="mt-1 font-sans text-sm leading-relaxed text-ink-2">
                    {stoppedPolling
                        ? 'The deeper fetch still has not landed. I stopped reloading on your behalf rather than doing it forever, so this one is on you now.'
                        : 'So far I have the distance, time and pace Strava lists for it. The splits, heart-rate zones, effort score and its card come from a second, deeper fetch that queues behind runs finishing right now, so it can take a few minutes. This page refreshes itself when the rest arrives.'}
                </p>
                {stoppedPolling && (
                    <PillButton
                        tone="outline"
                        size="sm"
                        className="mt-3"
                        onClick={() => router.reload()}
                    >
                        Check again
                    </PillButton>
                )}
            </div>
        </div>
    );
}
