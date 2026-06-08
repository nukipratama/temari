import { usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import LinkCard from '@/components/ui/LinkCard';
import SectionLabel from '@/components/ui/SectionLabel';
import ProgressBar from '@/components/ui/ProgressBar';
import { formatGoalNumber, goalProgressRatio } from '@/lib/goalProgress';
import type { SharedProps } from '@/types/inertia';

export default function GoalsCard() {
    const { props } = usePage<SharedProps>();
    const summary = props.goalsSummary;

    if (!summary || summary.closest.length === 0) {
        return null;
    }

    return (
        <section className="mt-8">
            <SectionLabel>
                <span className="inline-flex items-center gap-2">
                    <Icon icon="mdi:target" width={14} height={14} aria-hidden />
                    Target terdekat
                </span>
            </SectionLabel>
            <div className="grid gap-3 sm:grid-cols-3">
                {summary.closest.map((goal) => {
                    const ratio = goalProgressRatio(goal.current, goal.target);

                    return (
                        <LinkCard key={goal.id} href="/target" padding="md" className="flex h-full flex-col gap-2">
                            <div className="font-display text-base leading-tight tracking-[-0.01em] text-ink">
                                {goal.title}
                            </div>
                            <div className="mt-auto">
                                <div className="mb-1.5 flex items-baseline justify-between">
                                    <span className="font-sans text-sm font-semibold tabular-nums text-ink">
                                        {formatGoalNumber(goal.current)}<span className="text-ink-3">/</span>{formatGoalNumber(goal.target)}
                                    </span>
                                    <span className="font-mono text-[11px] font-semibold uppercase tracking-[0.1em] text-ink-3">
                                        {goal.unit}
                                    </span>
                                </div>
                                <ProgressBar value={ratio} tone="horizon" />
                            </div>
                        </LinkCard>
                    );
                })}
            </div>
        </section>
    );
}
