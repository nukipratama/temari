export interface CtlPoint {
    date: string;
    atl: number;
    ctl: number;
}

/**
 * Every headline number on /trends' "vs a month ago" and "vs your own year"
 * comparisons is read off the same 365-day `ctlTrend` series the chart
 * plots — oldest first, ending today — rather than a second backend query.
 */
export function ctlNow(trend: ReadonlyArray<CtlPoint>): number | null {
    return trend.length === 0 ? null : trend[trend.length - 1].ctl;
}

/** The series entry `daysAgo` days before the last one, or null when the
 *  series doesn't reach back that far yet. */
export function ctlDaysAgo(
    trend: ReadonlyArray<CtlPoint>,
    daysAgo: number,
): number | null {
    const index = trend.length - 1 - daysAgo;
    return index >= 0 ? trend[index].ctl : null;
}

/** The highest CTL the series reaches — "best this year" against the
 *  trailing 365 days it covers. */
export function ctlPeak(trend: ReadonlyArray<CtlPoint>): number | null {
    return trend.length === 0
        ? null
        : Math.max(...trend.map((point) => point.ctl));
}
