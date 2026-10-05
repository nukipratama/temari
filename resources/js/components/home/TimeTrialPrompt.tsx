import { router } from '@inertiajs/react';
import { useState } from 'react';

import PillButton from '@/components/ui/PillButton';
import { parseNaiveLocalDate } from '@/lib/pace';

export interface PendingTimeTrial {
    id: number;
    date: string;
    distance_m: number;
}

const WEEKDAY = new Intl.DateTimeFormat('en-US', { weekday: 'long' });

/**
 * The one ask for a time trial whose run did not clear the bar on its own:
 * only the athlete knows whether it was all-out, so Home asks once, plainly.
 */
export default function TimeTrialPrompt({
    trial,
}: Readonly<{ trial: PendingTimeTrial }>) {
    const [processing, setProcessing] = useState(false);
    const date = parseNaiveLocalDate(trial.date);
    const day = date === null ? 'that day' : WEEKDAY.format(date);
    const distance = `${Math.round(trial.distance_m / 1000)}K`;

    const answer = (allOut: boolean) => {
        router.post(
            `/plan/time-trials/${trial.id}`,
            { all_out: allOut },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <section aria-label="time trial check">
            <p className="text-base font-semibold text-foreground">
                was {day}&apos;s {distance} your all-out trial?
            </p>
            <p className="mt-1 mb-2.5 text-xs leading-relaxed text-text-2">
                it was not quick enough to count on its own. if it was all-out,
                it counts as fitness evidence and your paces follow it.
            </p>
            <div className="flex flex-wrap gap-2">
                <PillButton
                    tone="horizon"
                    size="sm"
                    disabled={processing}
                    onClick={() => answer(true)}
                >
                    yes, count it
                </PillButton>
                <PillButton
                    tone="ghost"
                    size="sm"
                    disabled={processing}
                    onClick={() => answer(false)}
                >
                    no, it wasn&apos;t all-out
                </PillButton>
            </div>
        </section>
    );
}
