import { Wand2 } from 'lucide-react';

import type { AthleteRow, RuleBasedReasons } from '@/pages/Narration/types';

import SectionHeading from '@/components/SectionHeading';
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/cn';
import {
    athleteLabel,
    FLAGGED_REASONS,
    fmt,
    REASON_LABEL,
} from '@/pages/Narration/helpers';

const REASON_ORDER: ReadonlyArray<keyof RuleBasedReasons> = [
    'demo',
    'capped',
    'return',
    'dead_letter',
    'content_filter',
    'unattributed',
];

interface RuleBasedPanelProps {
    athletes: AthleteRow[];
}

/**
 * The app-wide rule-based ledger: one row per reason with its count and which
 * athletes contributed it, plus the two anchors that give the numbers scale
 * (how much pre-dates `served_by`, how much the LLM wrote). Content-filter,
 * dead-letter and unattributed are the reasons that should not be there, so
 * they render in ember.
 */
export default function RuleBasedPanel({
    athletes,
}: Readonly<RuleBasedPanelProps>) {
    const totals: Partial<Record<keyof RuleBasedReasons, number>> = {};
    const contributors: Partial<Record<keyof RuleBasedReasons, string[]>> = {};
    let unknown = 0;
    let llm = 0;

    for (const athlete of athletes) {
        llm += athlete.served.llm;
        unknown += athlete.served.unknown;

        for (const reason of REASON_ORDER) {
            const count = athlete.served.reasons[reason];
            if (count <= 0) {
                continue;
            }

            totals[reason] = (totals[reason] ?? 0) + count;
            (contributors[reason] ??= []).push(
                `${athleteLabel(athlete.user_name, athlete.user_id)} ${fmt(count)}`,
            );
        }
    }

    const entries = REASON_ORDER.filter((reason) => (totals[reason] ?? 0) > 0);

    return (
        <section className="mt-10">
            <SectionHeading
                icon={Wand2}
                title="served rule-based"
                subtitle="Every fill with the reason it happened. An unexplained one is the interesting one."
                tone="accent"
            />

            <Card className="mt-4 bg-popover px-4 py-1">
                {entries.length === 0 && unknown === 0 ? (
                    <p className="py-6 text-center text-sm text-text-3">
                        Nothing served rule-based. Every done block in range
                        came from an LLM narrator.
                    </p>
                ) : (
                    <ul>
                        {entries.map((reason) => (
                            <li
                                key={reason}
                                className="grid grid-cols-[1fr_auto] items-baseline gap-x-3 gap-y-0.5 border-b border-border/45 py-2.5 last:border-b-0"
                            >
                                <span
                                    className={cn(
                                        'text-sm',
                                        FLAGGED_REASONS.has(reason)
                                            ? 'font-semibold text-ember-ink'
                                            : 'text-foreground',
                                    )}
                                >
                                    {REASON_LABEL[reason]}
                                </span>
                                <span className="font-mono text-sm font-bold text-foreground tabular-nums">
                                    {fmt(totals[reason] ?? 0)}
                                </span>
                                <span className="col-span-2 font-mono text-[0.6875rem] text-text-3">
                                    {contributors[reason]?.join(' · ')}
                                </span>
                            </li>
                        ))}

                        {unknown > 0 && (
                            <li className="grid grid-cols-[1fr_auto] gap-x-3 border-b border-border/45 py-2.5 text-text-3 last:border-b-0">
                                <span className="text-sm">
                                    pre-dates served_by, producer not recorded
                                </span>
                                <span className="font-mono text-sm tabular-nums">
                                    {fmt(unknown)}
                                </span>
                            </li>
                        )}

                        <li className="grid grid-cols-[1fr_auto] gap-x-3 py-2.5 text-text-3">
                            <span className="text-sm">
                                written by an llm narrator
                            </span>
                            <span className="font-mono text-sm tabular-nums">
                                {fmt(llm)}
                            </span>
                        </li>
                    </ul>
                )}
            </Card>
        </section>
    );
}
