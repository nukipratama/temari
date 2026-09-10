import { useForm } from '@inertiajs/react';
import { Users } from 'lucide-react';

import type { AthleteRow } from '@/pages/Narration/types';

import EmptyState from '@/components/narration/EmptyState';
import Sparkline from '@/components/narration/Sparkline';
import DataTable, { Td } from '@/components/ui/DataTable';
import ProgressBar from '@/components/ui/ProgressBar';
import {
    athleteLabel,
    athletePath,
    fmt,
    formatCost,
} from '@/pages/Narration/helpers';

const COLUMNS = [
    'athlete',
    'today',
    '7d',
    '30d',
    'calls',
    'ceiling',
    '30 days',
    'served by',
    'flags',
    'dead-lettered',
];

export default function AthleteTable({
    rows,
    currency,
}: Readonly<{ rows: AthleteRow[]; currency: string }>) {
    return (
        <DataTable
            icon={Users}
            title="athletes"
            subtitle="What each athlete costs, and what their narration was served by."
            tone="brand"
            columns={COLUMNS}
            minWidth={1080}
            rows={rows}
            rowKey={(row) => row.user_id}
            emptyState={<EmptyState />}
            renderRow={(row) => <AthleteCells row={row} currency={currency} />}
        />
    );
}

function AthleteCells({
    row,
    currency,
}: Readonly<{ row: AthleteRow; currency: string }>) {
    return (
        <>
            <td className="px-5 py-3 font-medium text-foreground">
                <a
                    href={athletePath(row.user_id)}
                    className="focus-ring rounded-sm hover:underline"
                >
                    {athleteLabel(row.user_name, row.user_id)}
                </a>
                <div className="mt-0.5 flex flex-wrap gap-1">
                    {row.is_demo && <Tag label="demo" />}
                    {row.deleted && <Tag label="deleted" />}
                    {row.capped && <Tag label="capped" alert />}
                </div>
            </td>
            <Td className="font-semibold text-foreground">
                {formatCost(row.today, currency)}
            </Td>
            <Td>{formatCost(row.last7, currency)}</Td>
            <Td>{formatCost(row.last30, currency)}</Td>
            <Td>{fmt(row.calls)}</Td>
            <td className="px-5 py-3 text-text-2">
                <CeilingCell row={row} currency={currency} />
            </td>
            <td className="px-5 py-3">
                <Sparkline
                    values={row.sparkline.map((point) => point.cost)}
                    ariaLabel={`${athleteLabel(row.user_name, row.user_id)}: daily spend over the last 30 days`}
                />
            </td>
            <td className="px-5 py-3 text-text-2">
                <ServedCell row={row} />
            </td>
            <Td className={row.flags > 0 ? 'text-ember-ink' : undefined}>
                {row.flags > 0 ? fmt(row.flags) : '—'}
            </Td>
            <td className="px-5 py-3 text-text-2">
                {row.dead_lettered > 0 ? (
                    <div className="flex items-center gap-2">
                        <span className="font-semibold text-mood-gassed-ink">
                            {fmt(row.dead_lettered)}
                        </span>
                        <RetryButton userId={row.user_id} />
                    </div>
                ) : (
                    '—'
                )}
            </td>
        </>
    );
}

function CeilingCell({
    row,
    currency,
}: Readonly<{ row: AthleteRow; currency: string }>) {
    if (row.ceiling === null) {
        return <span className="text-text-3">no ceiling</span>;
    }

    return (
        <>
            <div className="whitespace-nowrap tabular-nums">
                {formatCost(row.today, currency)} /{' '}
                {formatCost(row.ceiling, currency)}
                {row.ceiling_overridden && (
                    <span className="ml-1 text-text-3">override</span>
                )}
            </div>
            <ProgressBar
                value={row.ceiling > 0 ? row.today / row.ceiling : 0}
                size="sm"
                tone={row.capped ? 'sky' : 'horizon'}
                ariaLabel={`${athleteLabel(row.user_name, row.user_id)}: today against their ceiling`}
                className="mt-1 max-w-[120px]"
            />
        </>
    );
}

/**
 * The producer split of this athlete's Done narration in range. `unknown` is
 * shown as its own number rather than folded in: it means the row predates the
 * `served_by` column, not that a filler answered.
 */
function ServedCell({ row }: Readonly<{ row: AthleteRow }>) {
    const { llm, rule_based: ruleBased, unknown } = row.served;
    const done = llm + ruleBased + unknown;

    if (done === 0) {
        return <span className="text-text-3">—</span>;
    }

    return (
        <>
            <div className="whitespace-nowrap tabular-nums">
                {Math.round((llm / done) * 100)}% llm
            </div>
            <div className="font-mono text-[0.6875rem] text-text-3">
                {llm} llm · {ruleBased} rule · {unknown} unknown
            </div>
        </>
    );
}

function Tag({
    label,
    alert = false,
}: Readonly<{ label: string; alert?: boolean }>) {
    return (
        <span
            className={
                alert
                    ? 'rounded-full bg-ember/[0.15] px-1.5 py-0.5 text-[0.625rem] font-normal text-ember-ink'
                    : 'rounded-full bg-muted px-1.5 py-0.5 text-[0.625rem] font-normal text-text-2'
            }
        >
            {label}
        </span>
    );
}

/**
 * Re-arms and re-dispatches this athlete's failed blocks. Rendered only where
 * something is dead-lettered, so the button never promises work it has none of.
 */
function RetryButton({ userId }: Readonly<{ userId: number }>) {
    const { post, processing } = useForm();

    return (
        <button
            type="button"
            onClick={() =>
                post(`${athletePath(userId)}/retry-failed`, {
                    preserveScroll: true,
                })
            }
            disabled={processing}
            className="focus-ring inline-flex shrink-0 items-center rounded-full bg-sky px-3 py-1.5 text-xs font-semibold text-cream transition-colors hover:bg-sky-deep disabled:cursor-wait disabled:opacity-60"
        >
            retry failed
        </button>
    );
}
