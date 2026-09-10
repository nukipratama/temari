import { router, usePage } from '@inertiajs/react';
import {
    CircleAlert,
    Clock,
    RefreshCw,
    RotateCcw,
    Sailboat,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';

import type {
    AttentionBlock,
    AuditRow,
    CeilingOverride,
} from '@/pages/Narration/types';
import type { SharedProps } from '@/types/inertia';

import { Icon, type IconComponent } from '@/components/ui/Icon';
import Card from '@/components/ui/LegacyCard';
import PillButton from '@/components/ui/PillButton';

import ConfirmAction from './ConfirmAction';
import { formatCost, formatTimestamp } from './format';

interface AttentionTabProps {
    athleteId: number;
    currency: string;
    failed: AttentionBlock[];
    deadLettered: AttentionBlock[];
    stuck: AttentionBlock[];
    audit: AuditRow[];
    override: CeilingOverride | null;
}

export default function AttentionTab({
    athleteId,
    currency,
    failed,
    deadLettered,
    stuck,
    audit,
    override,
}: Readonly<AttentionTabProps>) {
    const base = `/devtools/narration/athletes/${athleteId}`;

    return (
        <section className="mt-6 flex flex-col gap-4">
            <Bucket
                icon={CircleAlert}
                title="failed, still auto-retrying"
                blocks={failed}
                action={
                    <ConfirmAction
                        label="retry all failed"
                        icon={RefreshCw}
                        action={`${base}/retry-failed`}
                        question="re-arm and re-dispatch every failed block for this athlete."
                    />
                }
            />

            <Bucket
                icon={CircleAlert}
                title="dead-lettered"
                blocks={deadLettered}
                action={
                    <ConfirmAction
                        label="re-arm dead-lettered"
                        icon={RotateCcw}
                        action={`${base}/re-arm`}
                        question="reset the self-heal budget on every dead-lettered block and re-dispatch it."
                    />
                }
            />

            <Bucket
                icon={Clock}
                title="stuck in flight"
                blocks={stuck}
                action={
                    <ConfirmAction
                        label="resync strava"
                        icon={Sailboat}
                        action={`${base}/resync`}
                        question="queue a full Strava sync for this athlete."
                    />
                }
            />

            <CeilingOverrideCard
                athleteId={athleteId}
                currency={currency}
                override={override}
            />

            <AuditLog rows={audit} />
        </section>
    );
}

function Bucket({
    icon,
    title,
    blocks,
    action,
}: Readonly<{
    icon: IconComponent;
    title: string;
    blocks: AttentionBlock[];
    action: ReactNode;
}>) {
    return (
        <Card tone="card" padding="card" className="bg-popover">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="flex items-center gap-2 text-sm font-semibold text-foreground">
                    <Icon icon={icon} aria-hidden />
                    {title}
                    <span className="font-mono text-xs text-text-3">
                        {blocks.length}
                    </span>
                </p>
                {action}
            </div>

            {blocks.length > 0 && (
                <ul className="mt-3 flex flex-col gap-1">
                    {blocks.map((block) => (
                        <li
                            key={block.id}
                            className="flex flex-wrap gap-x-3 font-mono text-xs text-text-3"
                        >
                            <span className="uppercase text-foreground">
                                {block.kind}
                            </span>
                            <span>attempt {block.attempts}</span>
                            <span>{formatTimestamp(block.at)}</span>
                            {block.error !== null && (
                                <span className="text-ember-ink">
                                    {block.error}
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

function CeilingOverrideCard({
    athleteId,
    currency,
    override,
}: Readonly<{
    athleteId: number;
    currency: string;
    override: CeilingOverride | null;
}>) {
    const base = `/devtools/narration/athletes/${athleteId}`;
    const [armed, setArmed] = useState(false);
    const [ceiling, setCeiling] = useState('');
    const error = usePage<SharedProps>().props.errors?.ceiling;

    return (
        <Card tone="card" padding="card" className="bg-popover">
            <p className="text-sm font-semibold text-foreground">
                today-only ceiling override
            </p>
            <p className="mt-1 text-xs text-text-3">
                {override === null
                    ? 'no override active. the configured slice applies.'
                    : `active at ${formatCost(override.value, currency)}, expires ${formatTimestamp(override.expires_at)}.`}
            </p>

            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    setArmed(true);
                }}
                className="mt-3 flex flex-wrap items-end gap-2"
            >
                <label
                    htmlFor="ceiling-override"
                    className="flex flex-col gap-1 font-mono text-xs font-bold uppercase tracking-wider text-text-2"
                >
                    dollars for today
                    <input
                        id="ceiling-override"
                        type="number"
                        step="0.01"
                        min="0"
                        value={ceiling}
                        onChange={(event) => setCeiling(event.target.value)}
                        className="focus-ring w-32 rounded-xl border border-border bg-muted px-3 py-2 text-sm tabular-nums text-foreground focus:border-leaf"
                    />
                </label>
                {armed ? (
                    <div className="flex flex-wrap items-center gap-2 rounded-xl border border-border bg-muted px-3 py-2">
                        <p className="text-xs text-text-2">
                            let this athlete spend{' '}
                            {formatCost(Number(ceiling) || 0, currency)} today,
                            until local midnight.
                        </p>
                        <PillButton
                            type="button"
                            tone="sky"
                            size="sm"
                            onClick={() => {
                                setArmed(false);
                                router.post(
                                    `${base}/ceiling`,
                                    { ceiling },
                                    { preserveScroll: true },
                                );
                            }}
                        >
                            confirm
                        </PillButton>
                        <PillButton
                            type="button"
                            tone="ghost"
                            size="sm"
                            onClick={() => setArmed(false)}
                        >
                            cancel
                        </PillButton>
                    </div>
                ) : (
                    <PillButton type="submit" tone="sky" size="sm">
                        set for today
                    </PillButton>
                )}
                {override !== null && (
                    <ConfirmAction
                        label="clear override"
                        icon={RotateCcw}
                        tone="ghost"
                        action={`${base}/ceiling/clear`}
                        question="drop the override and fall back to the configured slice."
                    />
                )}
            </form>
            {error !== undefined && (
                <p className="mt-2 text-xs text-ember-ink">{error}</p>
            )}
        </Card>
    );
}

function AuditLog({ rows }: Readonly<{ rows: AuditRow[] }>) {
    return (
        <Card tone="card" padding="card" className="bg-popover">
            <p className="text-sm font-semibold text-foreground">
                recent operator actions
            </p>
            {rows.length === 0 ? (
                <p className="mt-1 text-xs text-text-3">
                    nothing has been done to this athlete from devtools yet.
                </p>
            ) : (
                <ul className="mt-3 flex flex-col gap-1">
                    {rows.map((row) => (
                        <li
                            key={`${row.action}-${row.at}`}
                            className="flex flex-wrap gap-x-3 font-mono text-xs text-text-3"
                        >
                            <span className="text-foreground">
                                {row.action}
                            </span>
                            <span>{row.actor}</span>
                            <span>{formatTimestamp(row.at)}</span>
                            {row.payload !== null && (
                                <span>{JSON.stringify(row.payload)}</span>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}
