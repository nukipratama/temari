const numberFmt = new Intl.NumberFormat('en-US');

export function formatCost(amount: number, currency: string): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency,
        currencyDisplay: 'narrowSymbol',
        minimumFractionDigits: amount !== 0 && Math.abs(amount) < 0.01 ? 4 : 2,
        maximumFractionDigits: 4,
    }).format(amount);
}

export function formatCount(value: number): string {
    return numberFmt.format(value);
}

/** A `Y-m-d` day as a short `Sep 10` label, without crossing a timezone. */
export function formatDay(day: string): string {
    return new Date(`${day}T00:00:00`).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
    });
}

export function formatTimestamp(iso: string | null): string {
    if (iso === null) {
        return '—';
    }
    return new Date(iso).toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}
