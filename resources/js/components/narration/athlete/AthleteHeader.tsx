import type { AthleteHeaderData } from '@/pages/Narration/types';

import Card from '@/components/ui/LegacyCard';
import ProgressBar from '@/components/ui/ProgressBar';
import { formatCost } from '@/pages/Narration/helpers';

import Sparkline from './Sparkline';

/**
 * What this athlete costs: today against whichever ceiling is actually in
 * force, the last thirty days as a shape, and where the month lands if the
 * trailing week repeats.
 */
export default function AthleteHeader({
    header,
}: Readonly<{ header: AthleteHeaderData }>) {
    const { currency, ceiling, today_spend: today, forecast } = header;
    const ratio =
        ceiling.value === null || ceiling.value === 0
            ? 0
            : today / ceiling.value;

    return (
        <div className="mt-6 grid gap-4 md:grid-cols-3">
            <Card tone="card" padding="card" className="bg-popover">
                <p className="text-label-micro font-semibold uppercase text-text-3">
                    today
                </p>
                <p className="mt-1 text-display-xs tabular-nums text-foreground">
                    {formatCost(today, currency)}
                </p>
                {ceiling.value === null ? (
                    <p className="mt-2 text-xs text-text-3">
                        no per-athlete ceiling configured.
                    </p>
                ) : (
                    <>
                        <ProgressBar
                            value={ratio}
                            ariaLabel="today's spend against this athlete's ceiling"
                            className="mt-3"
                        />
                        <p className="mt-2 text-xs text-text-3">
                            of {formatCost(ceiling.value, currency)}{' '}
                            {ceiling.source === 'override'
                                ? '· today-only override'
                                : '· configured slice'}
                        </p>
                    </>
                )}
            </Card>

            <Card tone="card" padding="card" className="bg-popover">
                <p className="text-label-micro font-semibold uppercase text-text-3">
                    last 30 days
                </p>
                <Sparkline points={header.sparkline} currency={currency} />
            </Card>

            <Card tone="card" padding="card" className="bg-popover">
                <p className="text-label-micro font-semibold uppercase text-text-3">
                    month to date
                </p>
                <p className="mt-1 text-display-xs tabular-nums text-foreground">
                    {formatCost(forecast.month_to_date, currency)}
                </p>
                <p className="mt-2 text-sm text-text-2">
                    projection{' '}
                    <span className="font-semibold tabular-nums text-foreground">
                        {formatCost(forecast.projected, currency)}
                    </span>
                </p>
                <p className="mt-1 text-xs text-text-3">
                    {formatCost(forecast.daily_rate, currency)}/day over{' '}
                    {forecast.days_remaining} day(s) left, from the last 7 days.
                </p>
            </Card>
        </div>
    );
}
