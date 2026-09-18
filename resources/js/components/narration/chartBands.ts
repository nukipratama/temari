/**
 * Stack colours, in the chart's own order (most expensive kind first). Cycled
 * rather than mapped per kind: the set of kinds in range is open. Shared by
 * {@link CostChart} (the stacked bars) and {@link NarratorRanking} (the ranked
 * legend beside it), so a band always means the same kind in both places.
 */
const BANDS = [
    'bg-horizon',
    'bg-leaf',
    'bg-ember',
    'bg-citrus',
    'bg-rarity-rare',
    'bg-mood-easy',
    'bg-mood-wobbly',
    'bg-mood-blazing',
] as const;

export function band(index: number): string {
    return BANDS[index % BANDS.length];
}
