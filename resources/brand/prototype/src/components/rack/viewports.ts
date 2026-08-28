export const VIEWPORTS = {
    mobile: {
        key: 'mobile',
        label: 'Mobile · 390×844',
        w: 390,
        h: 844,
        chrome: 'phone',
    },
    se: { key: 'se', label: 'SE · 320×568', w: 320, h: 568, chrome: 'phone' },
    tablet: {
        key: 'tablet',
        label: 'Tablet · 834×1112',
        w: 834,
        h: 1112,
        chrome: 'tablet',
    },
    desktop: {
        key: 'desktop',
        label: 'Desktop · 1280×800',
        w: 1280,
        h: 800,
        chrome: 'browser',
    },
    wide: {
        key: 'wide',
        label: 'Wide · 1536×864',
        w: 1536,
        h: 864,
        chrome: 'browser',
    },
} as const;

export type ViewportKey = keyof typeof VIEWPORTS;
