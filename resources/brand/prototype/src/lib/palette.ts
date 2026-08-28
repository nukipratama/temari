/**
 * Literal hex/rgba mirrors of index.css's theme-reactive tokens, for the
 * handful of places (Chart.js canvases) that can't resolve a CSS custom
 * property. Keep in sync with :root / [data-theme="dark"] by hand.
 */
export const CHART_PALETTE = {
    light: {
        line: '#546d23',
        grid: 'rgba(22,24,27,.08)',
        tick: '#60666d',
    },
    dark: {
        line: '#ade047',
        grid: 'rgba(241,245,248,.10)',
        tick: 'rgba(241,245,248,.5)',
    },
} as const;
