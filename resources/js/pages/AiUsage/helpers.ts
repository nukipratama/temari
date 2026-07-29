import { router } from '@inertiajs/react';
import { formatMonthDayId, formatWeekdayDayId } from '@/lib/pace';
import type { RangeToken } from './types';

const numberFmt = new Intl.NumberFormat('id-ID');

export function fmt(n: number): string {
    return numberFmt.format(n);
}

/** Format a cost as a currency string, scaled to the budget's currency. */
export function formatCost(amount: number, currency: string): string {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency,
        currencyDisplay: 'narrowSymbol',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(amount);
}

/**
 * Navigate the report. A preset range travels as a self-correcting `range`
 * token (resolved server-side, never stale); a custom Dari/Sampai window
 * travels as absolute `from`/`to`.
 */
export function navigate({ range, from, to, kind }: { range: RangeToken; from: string; to: string; kind: string | null }): void {
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
    router.get('/ai-usage', params, { preserveState: true, preserveScroll: true });
}

/** Build a durable, date-free preset href that preserves the active kind filter. */
export function presetHref(token: RangeToken, kind: string | null): string {
    const params = new URLSearchParams({ range: token });
    if (kind !== null) {
        params.set('kind', kind);
    }
    return `/ai-usage?${params.toString()}`;
}

export const PRESETS: ReadonlyArray<{ token: RangeToken; label: string }> = [
    { token: 'today', label: 'Hari ini' },
    { token: '7d', label: '7 hari' },
    { token: '30d', label: '30 hari' },
    { token: 'month', label: 'Bulan ini' },
    { token: 'all', label: 'Semua' },
];

export function formatDayLabel(day: string): string {
    return formatMonthDayId(new Date(day + 'T00:00:00'));
}

export function formatDayLabelShort(day: string): string {
    return formatWeekdayDayId(new Date(day + 'T00:00:00'));
}
