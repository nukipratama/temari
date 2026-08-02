import type { Budget } from '@/pages/AiUsage/types';

import Card from '@/components/ui/Card';
import ProgressBar from '@/components/ui/ProgressBar';
import { formatCost } from '@/pages/AiUsage/helpers';

export default function BudgetGauge({ budget }: Readonly<{ budget: Budget }>) {
    const { todayCost, dailyCeiling, currency } = budget;
    const hasCeiling = dailyCeiling !== null && dailyCeiling > 0;
    const ratio = hasCeiling ? todayCost / dailyCeiling : 0;
    const overBudget = hasCeiling && ratio > 1;
    const caveat =
        'Estimasi memakai harga list price dari config, bukan tagihan final.';

    return (
        <Card
            as="section"
            tone="cream"
            padding="md"
            className="mt-6 bg-surface-elev"
        >
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <span className="text-label-micro text-ink-2">
                    Anggaran Hari Ini
                </span>
                <span className="text-sm text-ink-2">
                    <span className="font-semibold text-ink">
                        {formatCost(todayCost, currency)}
                    </span>
                    {hasCeiling ? (
                        <>
                            {' / '}
                            {formatCost(dailyCeiling, currency)}
                        </>
                    ) : (
                        <span className="text-ink-3"> · tanpa batas</span>
                    )}
                </span>
            </div>

            {hasCeiling ? (
                <ProgressBar
                    value={ratio}
                    tone={overBudget ? 'sky' : 'horizon'}
                    ariaLabel={`Anggaran hari ini: ${Math.round(ratio * 100)}% terpakai`}
                    className="mt-3"
                />
            ) : (
                <p className="mt-3 text-xs text-ink-3">
                    Tidak ada batas harian yang disetel.
                </p>
            )}

            {overBudget && (
                <p className="mt-2 text-xs font-semibold text-mood-lemes">
                    Melewati batas harian sebesar{' '}
                    {formatCost(todayCost - dailyCeiling, currency)}.
                </p>
            )}

            <p className="mt-3 text-xs text-ink-3">{caveat}</p>
        </Card>
    );
}
