import { Users } from 'lucide-react';

import type { AthleteRow } from '@/pages/Narration/types';

import EmptyState from '@/components/narration/EmptyState';
import Sparkline from '@/components/narration/Sparkline';
import SectionHeading from '@/components/SectionHeading';
import { Card } from '@/components/ui/card';
import ProgressBar from '@/components/ui/ProgressBar';
import {
    athleteLabel,
    athletePath,
    fmt,
    formatCost,
} from '@/pages/Narration/helpers';

interface AthletesPanelProps {
    rows: AthleteRow[];
    currency: string;
}

/**
 * One flex row per athlete, replacing the old table: name and tags, today
 * against their own ceiling, 7d, 30d, calls and a sparkline. Flags and the
 * dead-letter count dropped from here on purpose — the dead-letter recovery
 * lives on {@link FaultStrip} now, and quality signals live on the breakdown
 * tab. A deleted account collapses into a `<details>` rollup below the live
 * rows instead of taking a full row.
 */
export default function AthletesPanel({
    rows,
    currency,
}: Readonly<AthletesPanelProps>) {
    const live = rows.filter((row) => !row.deleted);
    const gone = rows.filter((row) => row.deleted);

    return (
        <section className="mt-10">
            <SectionHeading
                icon={Users}
                title="who drove it"
                subtitle="Today against each athlete's own ceiling, then their period cost."
                tone="brand"
            />

            {rows.length === 0 ? (
                <EmptyState />
            ) : (
                <Card className="mt-4 bg-popover px-4 py-1">
                    {live.map((row) => (
                        <AthleteRowView
                            key={row.user_id}
                            row={row}
                            currency={currency}
                        />
                    ))}

                    {gone.length > 0 && (
                        <details className="border-t border-dashed border-border-strong pt-2.5 pb-2">
                            <summary className="cursor-pointer font-mono text-xs text-text-3">
                                {gone.length} deleted account
                                {gone.length === 1 ? '' : 's'} ·{' '}
                                {formatCost(
                                    gone.reduce(
                                        (sum, row) => sum + row.last30,
                                        0,
                                    ),
                                    currency,
                                )}{' '}
                                in the last 30 days
                            </summary>
                            <div className="mt-1.5 grid gap-1">
                                {gone.map((row) => (
                                    <div
                                        key={row.user_id}
                                        className="flex flex-wrap items-baseline gap-3 py-1.5 text-xs text-text-3"
                                    >
                                        <span>
                                            {athleteLabel(
                                                row.user_name,
                                                row.user_id,
                                            )}
                                        </span>
                                        <span className="font-mono tabular-nums">
                                            30d{' '}
                                            {formatCost(row.last30, currency)}
                                        </span>
                                        <span className="font-mono tabular-nums">
                                            {fmt(row.calls)} calls
                                        </span>
                                        <span>
                                            account deleted, spend kept for the
                                            bill
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </details>
                    )}
                </Card>
            )}
        </section>
    );
}

function AthleteRowView({
    row,
    currency,
}: Readonly<{ row: AthleteRow; currency: string }>) {
    const capRatio = row.ceiling ? Math.min(row.today / row.ceiling, 1) : 0;
    const label = athleteLabel(row.user_name, row.user_id);

    return (
        <div className="grid grid-cols-2 gap-x-4 gap-y-3 border-b border-border/55 py-3 last:border-b-0 sm:grid-cols-3 lg:grid-cols-[minmax(150px,1.1fr)_minmax(120px,0.9fr)_repeat(3,minmax(58px,0.5fr))_minmax(96px,0.8fr)] lg:items-center">
            <div className="col-span-2 sm:col-span-1">
                <a
                    href={athletePath(row.user_id)}
                    className="focus-ring rounded-sm text-sm font-bold text-foreground hover:underline"
                >
                    {label}
                </a>
                <div className="mt-1 flex flex-wrap gap-1">
                    {row.is_demo && <Tag label="demo" />}
                    {row.capped && <Tag label="capped" alert />}
                    {row.ceiling_overridden && <Tag label="override" />}
                </div>
            </div>

            <div className="col-span-2 sm:col-span-2 lg:col-span-1">
                <span className="text-label-micro text-text-3">
                    today / their ceiling
                </span>
                <div className="mt-0.5 whitespace-nowrap font-mono text-sm text-foreground tabular-nums">
                    {formatCost(row.today, currency)}
                    <span className="text-text-3"> of </span>
                    <span>
                        {row.ceiling === null
                            ? 'no ceiling'
                            : formatCost(row.ceiling, currency)}
                    </span>
                </div>
                {row.ceiling !== null && (
                    <ProgressBar
                        value={capRatio}
                        size="sm"
                        tone={row.capped ? 'sky' : 'horizon'}
                        ariaLabel={`${label}: today against their ceiling`}
                        className="mt-1"
                    />
                )}
            </div>

            <Fact label="7 days" value={formatCost(row.last7, currency)} />
            <Fact label="30 days" value={formatCost(row.last30, currency)} />
            <Fact label="calls" value={fmt(row.calls)} />

            <div className="col-span-2 sm:col-span-3 lg:col-span-1">
                <span className="text-label-micro text-text-3">
                    30-day shape
                </span>
                <div className="mt-1">
                    <Sparkline
                        values={row.sparkline.map((point) => point.cost)}
                        ariaLabel={`${label}: daily spend over the last 30 days`}
                    />
                </div>
            </div>
        </div>
    );
}

function Fact({ label, value }: Readonly<{ label: string; value: string }>) {
    return (
        <div>
            <span className="text-label-micro text-text-3">{label}</span>
            <div className="mt-0.5 font-mono text-sm text-foreground tabular-nums">
                {value}
            </div>
        </div>
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
