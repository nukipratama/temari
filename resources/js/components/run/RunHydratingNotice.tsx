import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { Icon } from '@/components/ui/Icon';
import PillButton from '@/components/ui/PillButton';
import { cn } from '@/lib/cn';

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
            className="flex items-start gap-3 rounded-md border border-border-strong bg-card p-4 shadow-e1"
        >
            <Icon
                icon={
                    stoppedPolling
                        ? 'mdi:clock-outline'
                        : 'mdi:progress-download'
                }
                width={18}
                height={18}
                className={cn(
                    'mt-0.5 shrink-0',
                    stoppedPolling ? 'text-text-3' : 'text-icon-accent',
                )}
                aria-hidden
            />
            <div className="flex-1">
                <p className="font-sans text-sm font-bold text-foreground">
                    {stoppedPolling
                        ? 'still waiting on the rest of this run'
                        : 'still filling this run in'}
                </p>
                <p className="mt-1 font-sans text-xs leading-relaxed text-text-2">
                    {stoppedPolling
                        ? 'the deeper fetch still has not landed. i stopped reloading on your behalf rather than doing it forever, so this one is on you now.'
                        : 'So far I have the distance, time and pace Strava lists for it. The splits, heart-rate zones, effort score and its card come from a second, deeper fetch that queues behind runs finishing right now, so it can take a few minutes. This page refreshes itself when the rest arrives.'}
                </p>
                {stoppedPolling && (
                    <PillButton
                        tone="outline"
                        size="sm"
                        className="mt-2.5"
                        onClick={() => router.reload()}
                    >
                        Check again
                    </PillButton>
                )}
            </div>
        </div>
    );
}
