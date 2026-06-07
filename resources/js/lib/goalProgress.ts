/**
 * Shared goal-progress math for the goal cards on HariIni (`GoalsCard`) and
 * Target (`GoalCard`). Keeps the ratio + number-formatting identical across the
 * two surfaces so the `<ProgressBar />` fill and the `current/target` readout
 * never drift between pages.
 */

/** Fill ratio `0`..`1`, clamped. Returns `0` when the target is non-positive. */
export function goalProgressRatio(current: number, target: number): number {
    if (target <= 0) {
        return 0;
    }
    return Math.min(current / target, 1);
}

/** Render a goal figure: one decimal place when fractional, integer otherwise. */
export function formatGoalNumber(value: number): string {
    return value % 1 !== 0 ? value.toFixed(1) : String(value);
}
