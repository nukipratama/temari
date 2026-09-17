import type { ReactNode } from 'react';

import type {
    AthleteRow,
    Budget,
    ContentFilterSummary,
} from '@/pages/Narration/types';

import {
    RecoverAllButton,
    RetryFailedButton,
} from '@/components/narration/actions';
import { athleteLabel, PAUSE_LABEL } from '@/pages/Narration/helpers';

interface FaultStripProps {
    pauseReason: string | null;
    budget: Budget;
    cappedToday: number;
    athletes: AthleteRow[];
    contentFilter: ContentFilterSummary;
}

interface Fault {
    key: string;
    head: string;
    value: string;
    body: string;
    action?: ReactNode;
}

/**
 * The operator's first read: every open fault as its own tile with the action
 * that fixes it, or a single "nothing on fire" line when there is nothing to
 * act on. Presence or absence of the region answers "is anything wrong?"
 * without scanning chips.
 */
export default function FaultStrip({
    pauseReason,
    budget,
    cappedToday,
    athletes,
    contentFilter,
}: Readonly<FaultStripProps>) {
    const faults = buildFaults(
        pauseReason,
        budget,
        cappedToday,
        athletes,
        contentFilter,
    );

    if (faults.length === 0) {
        return (
            <div className="mb-5 flex items-center gap-2 rounded-md border border-border bg-card pad-panel text-sm text-text-2">
                <span
                    aria-hidden
                    className="h-2 w-2 shrink-0 rounded-full bg-leaf"
                />
                <span>
                    nothing on fire. generating normally, nobody capped, no dead
                    letters, no filter trips.
                </span>
            </div>
        );
    }

    return (
        <section className="mb-5 rounded-md border border-ember bg-ember/[0.08] p-3.5">
            <div className="mb-2.5 flex items-center gap-2">
                <span
                    aria-hidden
                    className="h-2.5 w-2.5 shrink-0 rounded-full bg-ember"
                />
                <h2 className="font-serif text-lg text-ember-ink italic">
                    {faults.length} thing{faults.length === 1 ? '' : 's'} need
                    {faults.length === 1 ? 's' : ''} you
                </h2>
            </div>
            <ul className="grid gap-2">
                {faults.map((fault) => (
                    <li
                        key={fault.key}
                        className="grid grid-cols-[1fr_auto] items-start gap-x-3 gap-y-1.5 rounded-sm border border-ember/35 bg-card px-3 py-2.5"
                    >
                        <div>
                            <div className="text-sm font-bold text-foreground">
                                {fault.head}
                            </div>
                            {fault.value !== '' && (
                                <div className="mt-0.5 font-mono text-xs text-ember-ink">
                                    {fault.value}
                                </div>
                            )}
                        </div>
                        {fault.action}
                        <div className="col-span-2 text-xs text-text-3">
                            {fault.body}
                        </div>
                    </li>
                ))}
            </ul>
        </section>
    );
}

/**
 * Every open fault, most severe first. Ordering and copy mirror the
 * #929/A design round: a pause blocks generation outright, an app-wide
 * ceiling trip degrades everyone, then per-athlete caps, dead letters and
 * content-filter trips.
 */
function buildFaults(
    pauseReason: string | null,
    budget: Budget,
    cappedToday: number,
    athletes: AthleteRow[],
    contentFilter: ContentFilterSummary,
): Fault[] {
    const faults: Fault[] = [];

    if (pauseReason !== null) {
        faults.push({
            key: 'pause',
            head: 'generation paused',
            value: PAUSE_LABEL[pauseReason] ?? pauseReason,
            body: 'no narrator is dispatching. pending blocks stay pending.',
            action: <RecoverAllButton />,
        });
    }

    if (budget.trippedAt !== null) {
        faults.push({
            key: 'ceiling',
            head: 'app-wide ceiling tripped',
            value: `${budget.trippedAt.slice(11, 16)} today`,
            body: `${budget.degradedFills} block${budget.degradedFills === 1 ? '' : 's'} served rule-based since.`,
        });
    }

    if (cappedToday > 0) {
        const who = athletes
            .filter((athlete) => athlete.capped)
            .map((athlete) => athleteLabel(athlete.user_name, athlete.user_id))
            .join(', ');
        faults.push({
            key: 'capped',
            head: `${cappedToday} athlete${cappedToday === 1 ? '' : 's'} capped today`,
            value: who,
            body: 'their blocks are served rule-based until midnight.',
        });
    }

    const deadLettered = athletes.filter(
        (athlete) => athlete.dead_lettered > 0,
    );
    const deadTotal = deadLettered.reduce(
        (sum, athlete) => sum + athlete.dead_lettered,
        0,
    );
    if (deadTotal > 0) {
        faults.push({
            key: 'dead',
            head: `${deadTotal} dead-lettered block${deadTotal === 1 ? '' : 's'}`,
            value: deadLettered
                .map(
                    (athlete) =>
                        `${athleteLabel(athlete.user_name, athlete.user_id)} ${athlete.dead_lettered}`,
                )
                .join(' · '),
            body: 'self-heal gave up. they need a manual re-arm.',
            action: (
                <div className="flex flex-wrap justify-end gap-1.5">
                    {deadLettered.map((athlete) => (
                        <RetryFailedButton
                            key={athlete.user_id}
                            userId={athlete.user_id}
                        />
                    ))}
                </div>
            ),
        });
    }

    if (contentFilter.trips > 0) {
        faults.push({
            key: 'filter',
            head: `${contentFilter.trips} content-filter trip${contentFilter.trips === 1 ? '' : 's'}`,
            value:
                contentFilter.pct === null
                    ? 'no calls in range'
                    : `${contentFilter.pct}% of calls in range`,
            body: 'azure filtered the output; the block fell back to rule-based.',
        });
    }

    return faults;
}
