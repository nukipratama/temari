import { Flag, History, RefreshCw } from 'lucide-react';
import { useState } from 'react';

import type {
    NarrationRow as Row,
    ReplayBudget,
} from '@/pages/Narration/types';

import Chip from '@/components/ui/Chip';
import Card from '@/components/ui/LegacyCard';

import ConfirmAction from './ConfirmAction';
import { formatCost, formatCount, formatTimestamp } from './format';
import VersionDiff from './VersionDiff';

interface NarrationRowProps {
    row: Row;
    currency: string;
    athleteId: number;
    replayBudget: ReplayBudget;
}

/** Roughly three lines of prose; past it the text collapses behind a toggle. */
const COLLAPSE_AT = 220;

export default function NarrationRow({
    row,
    currency,
    athleteId,
    replayBudget,
}: Readonly<NarrationRowProps>) {
    const [expanded, setExpanded] = useState(false);
    const [showDiff, setShowDiff] = useState(false);

    const content = row.content ?? '';
    const collapsible = content.length > COLLAPSE_AT;
    const shown =
        collapsible && !expanded
            ? `${content.slice(0, COLLAPSE_AT)}…`
            : content;
    const toolCalls = row.tool_calls.map((call, index) => ({
        ...call,
        id: `${index}-${call.tool}`,
    }));

    return (
        <Card as="li" tone="card" padding="card" className="bg-popover">
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-mono text-xs font-semibold uppercase tracking-wider text-foreground">
                    {row.kind}
                </span>
                {row.discriminator !== null && (
                    <span className="font-mono text-xs text-text-3">
                        {row.discriminator}
                    </span>
                )}
                <Chip tone="neutral">{row.status}</Chip>
                <Chip tone="neutral">{row.origin ?? 'unattributed'}</Chip>
                <Chip tone="neutral">{row.served_by ?? 'unknown'}</Chip>
                {row.flag !== null && (
                    <Chip tone="neutral" className="text-ember-ink">
                        <Flag aria-hidden className="h-3 w-3" />
                        flagged
                        {row.flag.reason !== null ? `: ${row.flag.reason}` : ''}
                    </Chip>
                )}
                <span className="ml-auto font-mono text-xs tabular-nums text-text-3">
                    {formatTimestamp(row.generated_at)}
                </span>
            </div>

            <dl className="mt-3 flex flex-wrap gap-x-6 gap-y-1 font-mono text-xs tabular-nums text-text-3">
                <Stat label="cost" value={formatCost(row.cost, currency)} />
                <Stat
                    label="tokens"
                    value={`${formatCount(row.prompt_tokens)} in / ${formatCount(row.completion_tokens)} out`}
                />
                <Stat
                    label="latency"
                    value={
                        row.latency_ms === null
                            ? '—'
                            : `${formatCount(row.latency_ms)} ms`
                    }
                />
                <Stat label="steps" value={formatCount(row.steps)} />
            </dl>

            {toolCalls.length > 0 && (
                <ul className="mt-2 flex flex-wrap gap-1">
                    {toolCalls.map((call) => (
                        <li
                            key={call.id}
                            className="rounded-full bg-muted px-2 py-0.5 text-label-micro text-text-2"
                        >
                            {call.tool} · {call.arguments_summary} ·{' '}
                            {formatCount(call.duration_ms)}ms
                        </li>
                    ))}
                </ul>
            )}

            {row.flag?.note != null && row.flag.note !== '' && (
                <p className="mt-2 text-xs text-ember-ink">“{row.flag.note}”</p>
            )}

            {content !== '' && (
                <>
                    <p className="narration-dense mt-3 whitespace-pre-line">
                        {shown}
                    </p>
                    {collapsible && (
                        <button
                            type="button"
                            onClick={() => setExpanded(!expanded)}
                            className="focus-ring mt-1 rounded-full text-xs font-semibold text-leaf-ink"
                        >
                            {expanded ? 'show less' : 'show all'}
                        </button>
                    )}
                </>
            )}

            {row.error !== null && (
                <p className="mt-2 text-xs text-ember-ink">{row.error}</p>
            )}

            {row.version_count > 0 && (
                <div className="mt-3">
                    <button
                        type="button"
                        onClick={() => setShowDiff(!showDiff)}
                        className="focus-ring inline-flex items-center gap-1 rounded-full text-xs font-semibold text-leaf-ink"
                    >
                        <History aria-hidden className="h-3 w-3" />
                        {row.version_count} earlier version(s)
                    </button>
                    {showDiff && row.previous_content !== null && (
                        <VersionDiff
                            before={row.previous_content}
                            after={content}
                        />
                    )}
                </div>
            )}

            {row.flag !== null && (
                <div className="mt-3">
                    <ConfirmAction
                        label="replay"
                        icon={RefreshCw}
                        tone="ghost"
                        action={`/devtools/narration/athletes/${athleteId}/replay`}
                        data={{ analysis_id: row.id }}
                        disabledReason={
                            replayBudget.cap_reached
                                ? `replay budget spent for today (${formatCost(replayBudget.spent_today, currency)} of ${formatCost(replayBudget.cap ?? 0, currency)}). it resets at midnight.`
                                : null
                        }
                        question={`re-narrate this block. it last cost ${formatCost(row.last_cost, currency)}; today's replays are at ${formatCost(replayBudget.spent_today, currency)}${replayBudget.cap === null ? '' : ` of ${formatCost(replayBudget.cap, currency)}`}.`}
                    />
                </div>
            )}
        </Card>
    );
}

function Stat({ label, value }: Readonly<{ label: string; value: string }>) {
    return (
        <div className="flex gap-1">
            <dt className="uppercase tracking-wider">{label}</dt>
            <dd className="text-foreground">{value}</dd>
        </div>
    );
}
