import { router } from '@inertiajs/react';

import { formatMonthDayId, formatWeekdayDayId } from '@/lib/pace';

import type { RangeToken } from './types';

const numberFmt = new Intl.NumberFormat('en-US');

export const OVERVIEW_PATH = '/devtools/narration';

export function athletePath(userId: number): string {
    return `${OVERVIEW_PATH}/athletes/${userId}`;
}

export function fmt(n: number): string {
    return numberFmt.format(n);
}

/** Format a cost as a currency string, scaled to the budget's currency. */
export function formatCost(amount: number, currency: string): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency,
        currencyDisplay: 'narrowSymbol',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(amount);
}

interface ReportFilters {
    range: RangeToken;
    from: string;
    to: string;
    kind: string | null;
    origin: string | null;
    athlete?: number | null;
}

/**
 * Navigate the report. A preset range travels as a self-correcting `range`
 * token (resolved server-side, never stale); a custom From/To window
 * travels as absolute `from`/`to`.
 */
export function navigate({
    range,
    from,
    to,
    kind,
    origin,
    athlete = null,
}: ReportFilters): void {
    const params: Record<string, string> = {};
    if (range === 'custom') {
        params.from = from;
        params.to = to;
    } else {
        params.range = range;
    }
    if (kind !== null) {
        params.kind = kind;
    }
    if (origin !== null) {
        params.origin = origin;
    }
    if (athlete !== null) {
        params.athlete = String(athlete);
    }
    router.get(OVERVIEW_PATH, params, {
        preserveState: true,
        preserveScroll: true,
    });
}

/** Build a durable, date-free preset href that preserves the active filters. */
export function presetHref(
    token: RangeToken,
    kind: string | null,
    origin: string | null = null,
    athlete: number | null = null,
): string {
    const params = new URLSearchParams({ range: token });
    if (kind !== null) {
        params.set('kind', kind);
    }
    if (origin !== null) {
        params.set('origin', origin);
    }
    if (athlete !== null) {
        params.set('athlete', String(athlete));
    }
    return `${OVERVIEW_PATH}?${params.toString()}`;
}

export const PRESETS: ReadonlyArray<{ token: RangeToken; label: string }> = [
    { token: 'today', label: 'today' },
    { token: '7d', label: '7 days' },
    { token: '30d', label: '30 days' },
    { token: 'month', label: 'this month' },
    { token: 'all', label: 'all' },
];

export function formatDayLabel(day: string): string {
    return formatMonthDayId(new Date(day + 'T00:00:00'));
}

export function formatDayLabelShort(day: string): string {
    return formatWeekdayDayId(new Date(day + 'T00:00:00'));
}

/** The athlete's display name, falling back to the bare id for a deleted one. */
export function athleteLabel(name: string | null, userId: number): string {
    return name ?? `User #${userId}`;
}
