import type { RunHardMinutes } from '@/types/inertia';

import { formatHardMinutes } from '@/lib/hardMinutes';
import { formatPace } from '@/lib/pace';

const MIN_EASED_MINUTES = 1;

/**
 * Why a run's high heart rate did not all count as hard: how many minutes
 * heart rate alone would have counted, the heat behind them, and the easy
 * ceiling the pace stayed under. Drawn only when at least a minute of high
 * heart rate came at an easy pace.
 */
export default function HardMinuteHeatLine({
    hardMinutes,
    feelsLikeC,
    dewPointC,
}: Readonly<{
    hardMinutes: RunHardMinutes;
    feelsLikeC: number | null;
    dewPointC: number | null;
}>) {
    const {
        minutes,
        hr_only_minutes: hrOnly,
        easy_ceiling_sec_per_km: ceiling,
    } = hardMinutes;
    const eased = Math.round((hrOnly - minutes) * 10) / 10;
    if (ceiling == null || eased < MIN_EASED_MINUTES) {
        return null;
    }

    const facts = [
        `by HR alone ${formatHardMinutes(hrOnly)}`,
        feelsLikeC != null && `${Math.round(feelsLikeC)}° feels-like`,
        dewPointC != null && `dew point ${Math.round(dewPointC)}°`,
        minutes > 0
            ? `${formatHardMinutes(minutes)} counted hard`
            : 'counted as easy',
    ].filter((fact): fact is string => fact !== false);
    const ceilingPace = `${formatPace(ceiling)}/km`;

    return (
        <div className="mt-3">
            <p className="font-mono text-xs tabular-nums text-foreground">
                {facts.join(' · ')}
            </p>
            <p className="mt-0.5 font-sans text-xs text-text-3">
                {minutes > 0
                    ? `${formatHardMinutes(eased)} of those minutes came slower than your ${ceilingPace} easy ceiling and counted as easy.`
                    : `pace stayed slower than your ${ceilingPace} easy ceiling, so every minute counted as easy.`}
            </p>
        </div>
    );
}
