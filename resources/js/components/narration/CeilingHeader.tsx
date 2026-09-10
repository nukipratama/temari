import { useForm } from '@inertiajs/react';
import { RotateCcw } from 'lucide-react';

import type { Budget } from '@/pages/Narration/types';

import { Icon } from '@/components/ui/Icon';
import Card from '@/components/ui/LegacyCard';
import ProgressBar from '@/components/ui/ProgressBar';
import { formatCost, OVERVIEW_PATH } from '@/pages/Narration/helpers';

interface CeilingHeaderProps {
    budget: Budget;
    cappedToday: number;
    pauseReason: string | null;
}

const PAUSE_LABEL: Record<string, string> = {
    suppressed: 'dispatch suppressed',
    kill_switch: 'kill switch off',
    auto_dispatch: 'auto-dispatch off',
    unconfigured: 'azure not configured',
    config: 'config breaker tripped',
    cost_ceiling: 'cost ceiling hit',
};

/**
 * The one-line answer to "is today fine?": what the app has spent against the
 * app-wide stop, how many athletes are already capped, and whether anything is
 * generating at all.
 */
export default function CeilingHeader({
    budget,
    cappedToday,
    pauseReason,
}: Readonly<CeilingHeaderProps>) {
    const { todayCost, totalCeiling, currency, trippedAt, degradedFills } =
        budget;
    const hasCeiling = totalCeiling !== null && totalCeiling > 0;
    const ratio = hasCeiling ? todayCost / totalCeiling : 0;
    const trippedTime = trippedAt?.slice(11, 16);

    return (
        <Card
            as="section"
            tone="card"
            padding="card"
            className="mt-6 bg-popover"
        >
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <span className="text-label-micro text-text-2">
                    today, app-wide
                </span>
                <span className="text-sm text-text-2">
                    <span className="font-semibold text-foreground tabular-nums">
                        {formatCost(todayCost, currency)}
                    </span>
                    {hasCeiling ? (
                        <> / {formatCost(totalCeiling, currency)}</>
                    ) : (
                        <span className="text-text-3"> · no app-wide stop</span>
                    )}
                </span>
            </div>

            {hasCeiling ? (
                <ProgressBar
                    value={ratio}
                    tone={ratio > 1 ? 'sky' : 'horizon'}
                    ariaLabel={`app-wide ceiling: ${Math.round(ratio * 100)}% used`}
                    className="mt-3"
                />
            ) : (
                <p className="mt-3 text-xs text-text-3">
                    No app-wide ceiling set.
                </p>
            )}

            <div className="mt-3 flex flex-wrap items-center gap-2">
                <Chip
                    label={`${cappedToday} capped today`}
                    alert={cappedToday > 0}
                />
                <Chip
                    label={
                        pauseReason === null
                            ? 'generating'
                            : `paused: ${PAUSE_LABEL[pauseReason] ?? pauseReason}`
                    }
                    alert={pauseReason !== null}
                />
                {trippedTime !== undefined && (
                    <Chip
                        label={`tripped ${trippedTime} · ${degradedFills} served rule-based`}
                        alert
                    />
                )}
                <RecoverButton />
            </div>

            <p className="mt-3 text-xs text-text-3">
                Estimate uses list price from config, not the final bill.
            </p>
        </Card>
    );
}

function Chip({ label, alert }: Readonly<{ label: string; alert: boolean }>) {
    return (
        <span
            className={
                alert
                    ? 'rounded-full bg-ember/[0.15] pad-chip font-mono text-xs text-ember-ink'
                    : 'rounded-full bg-muted pad-chip font-mono text-xs text-text-2'
            }
        >
            {label}
        </span>
    );
}

/**
 * One-shot "resume everything": re-arms every dead-lettered block across
 * athletes and runs the self-heal sweep immediately, instead of an N-click,
 * up-to-60-min-cadence scavenger hunt.
 */
function RecoverButton() {
    const { post, processing } = useForm();

    return (
        <button
            type="button"
            onClick={() =>
                post(`${OVERVIEW_PATH}/recover`, { preserveScroll: true })
            }
            disabled={processing}
            className="focus-ring ml-auto inline-flex shrink-0 items-center gap-1.5 rounded-full bg-sky px-4 py-2 text-xs font-semibold text-cream transition-colors hover:bg-sky-deep disabled:cursor-wait disabled:opacity-60"
        >
            <Icon icon={RotateCcw} aria-hidden />
            <span>recover all</span>
        </button>
    );
}
