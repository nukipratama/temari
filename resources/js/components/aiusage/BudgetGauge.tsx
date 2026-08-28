import type { Budget } from '@/pages/AiUsage/types';

import Card from '@/components/ui/Card';
import ProgressBar from '@/components/ui/ProgressBar';
import { formatCost } from '@/pages/AiUsage/helpers';

export default function BudgetGauge({ budget }: Readonly<{ budget: Budget }>) {
    const { todayCost, dailyCeiling, currency, trippedAt, degradedFills } =
        budget;
    const trippedTime = trippedAt?.slice(11, 16);
    const hasCeiling = dailyCeiling !== null && dailyCeiling > 0;
    const ratio = hasCeiling ? todayCost / dailyCeiling : 0;
    const overBudget = hasCeiling && ratio > 1;
    const caveat = 'Estimate uses list price from config, not the final bill.';

    return (
        <Card
            as="section"
            tone="card"
            padding="card"
            className="mt-6 bg-popover"
        >
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <span className="text-label-micro text-text-2">
                    Today&apos;s Budget
                </span>
                <span className="text-sm text-text-2">
                    <span className="font-semibold text-foreground">
                        {formatCost(todayCost, currency)}
                    </span>
                    {hasCeiling ? (
                        <>
                            {' / '}
                            {formatCost(dailyCeiling, currency)}
                        </>
                    ) : (
                        <span className="text-text-3"> · no limit</span>
                    )}
                </span>
            </div>

            {hasCeiling ? (
                <ProgressBar
                    value={ratio}
                    tone={overBudget ? 'sky' : 'horizon'}
                    ariaLabel={`Today's budget: ${Math.round(ratio * 100)}% used`}
                    className="mt-3"
                />
            ) : (
                <p className="mt-3 text-xs text-text-3">No daily limit set.</p>
            )}

            {overBudget && (
                <p className="mt-2 text-xs font-semibold text-mood-gassed-ink">
                    Over the daily limit by{' '}
                    {formatCost(todayCost - dailyCeiling, currency)}.
                </p>
            )}

            {trippedTime !== undefined && (
                <p className="mt-2 font-mono text-xs text-text-2 tabular-nums">
                    Ceiling tripped {trippedTime} · {degradedFills}{' '}
                    {degradedFills === 1 ? 'reply' : 'replies'} served
                    rule-based
                </p>
            )}

            <p className="mt-3 text-xs text-text-3">{caveat}</p>
        </Card>
    );
}
