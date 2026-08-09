import { Icon } from '@iconify/react';
import { useForm, usePage } from '@inertiajs/react';

import type { DeadLetterGroup } from '@/pages/AiUsage/types';
import type { SharedProps } from '@/types/inertia';

import SectionHeading from '@/components/SectionHeading';

/**
 * The "stuck work" cluster: a global one-shot recover action plus three buckets
 * that stop the "healthy" dashboard from lying. Hidden entirely when nothing is
 * stuck across all three.
 */
export default function AttentionArea({
    deadLettered,
    failedUnderBudget,
    nyangkut,
}: Readonly<{
    deadLettered: DeadLetterGroup[];
    failedUnderBudget: DeadLetterGroup[];
    nyangkut: DeadLetterGroup[];
}>) {
    const hasAny =
        deadLettered.length + failedUnderBudget.length + nyangkut.length > 0;
    if (!hasAny) {
        return null;
    }

    return (
        <>
            <RecoverBar />

            <AttentionPanel
                title="Needs attention"
                subtitle="AI blocks that failed repeatedly and stopped auto-retrying. Retry manually per user."
                groups={deadLettered}
                countLabel="blocks stopped auto-retrying"
                actionable
            />

            <AttentionPanel
                title="Failed, not giving up yet"
                subtitle="Blocks currently failing but still auto-retrying. Can be forced to continue now, per user."
                groups={failedUnderBudget}
                countLabel="blocks failing, still auto-retrying"
                actionable
            />

            <AttentionPanel
                title="Stuck"
                subtitle="Pending/queued blocks stuck for a long time (their job got lost). Use Recover all above to clear them."
                groups={nyangkut}
                countLabel="stuck blocks (pending/queued)"
                actionable={false}
            />
        </>
    );
}

/**
 * One-shot "resume everything": re-arms every dead-lettered block across users
 * and runs the full self-heal sweep immediately, instead of an N-click,
 * up-to-60-min-cadence scavenger hunt. Sits above the buckets it recovers.
 */
function RecoverBar() {
    const { post, processing } = useForm();

    function recover(): void {
        post('/ai-usage/recover', { preserveScroll: true });
    }

    return (
        <div className="mt-10 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-line bg-surface-elev p-4">
            <p className="text-sm text-ink-2">
                Just recovered from an outage? Recover every stuck block at
                once.
            </p>
            <button
                type="button"
                onClick={recover}
                disabled={processing}
                className="focus-ring inline-flex shrink-0 items-center gap-1.5 rounded-full bg-sky px-4 py-2 text-xs font-semibold text-cream transition-colors hover:bg-sky-deep disabled:cursor-wait disabled:opacity-60"
            >
                <Icon icon="mdi:restore" aria-hidden />
                <span>{processing ? 'Recovering…' : 'Recover all'}</span>
            </button>
        </div>
    );
}

/**
 * A per-user bucket of stuck blocks. When `actionable`, each group carries a
 * "Retry all" that re-arms and re-dispatches all of that user's Failed
 * blocks. Hidden when its bucket is empty.
 */
function AttentionPanel({
    title,
    subtitle,
    groups,
    countLabel,
    actionable,
}: Readonly<{
    title: string;
    subtitle: string;
    groups: DeadLetterGroup[];
    countLabel: string;
    actionable: boolean;
}>) {
    if (groups.length === 0) {
        return null;
    }

    return (
        <section className="mt-10">
            <SectionHeading
                icon="mdi:alert-circle-outline"
                title={title}
                subtitle={subtitle}
                tone="accent"
            />

            <div className="mt-4 space-y-3">
                {groups.map((group) => (
                    <AttentionGroupRow
                        key={group.user_id}
                        group={group}
                        countLabel={countLabel}
                        actionable={actionable}
                    />
                ))}
            </div>
        </section>
    );
}

function AttentionGroupRow({
    group,
    countLabel,
    actionable,
}: Readonly<{
    group: DeadLetterGroup;
    countLabel: string;
    actionable: boolean;
}>) {
    const { post, processing } = useForm();
    const paused = usePage<SharedProps>().props.aiPaused ?? false;

    function retry(): void {
        post(`/ai-usage/users/${group.user_id}/retry-failed`, {
            preserveScroll: true,
        });
    }

    // Collapse the block list into "type ×N" chips so a user with many stuck
    // blocks of the same kind reads as one chip, not a repeated wall.
    const byType = group.blocks.reduce<Record<string, number>>((acc, block) => {
        acc[block.type] = (acc[block.type] ?? 0) + 1;
        return acc;
    }, {});

    return (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-line bg-surface-elev p-4">
            <div className="min-w-0">
                <p className="truncate font-medium text-ink">
                    {group.user_name}
                </p>
                <p className="text-xs text-ink-3">
                    {group.count} {countLabel}
                </p>
                <ul className="mt-2 flex flex-wrap gap-1.5">
                    {Object.entries(byType).map(([type, count]) => (
                        <li
                            key={type}
                            className="rounded-md bg-surface-sunken px-2 py-0.5 font-mono text-[11px] font-bold uppercase tracking-wider text-ink-3"
                        >
                            {type}
                            {count > 1 && (
                                <span className="ml-1 text-ink-2">
                                    ×{count}
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            </div>
            {actionable && (
                <button
                    type="button"
                    onClick={retry}
                    disabled={processing || paused}
                    title={
                        paused
                            ? 'Temari is resting, try again later'
                            : undefined
                    }
                    className="focus-ring inline-flex shrink-0 items-center gap-1.5 rounded-full bg-leaf-deep px-3 py-1.5 text-xs font-semibold text-cream transition-opacity hover:opacity-90 disabled:cursor-wait disabled:opacity-60"
                >
                    <Icon icon="mdi:auto-awesome" aria-hidden />
                    <span>{processing ? 'Sending…' : 'Retry all'}</span>
                </button>
            )}
        </div>
    );
}
