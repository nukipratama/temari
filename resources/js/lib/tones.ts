// Shared "icon tile" tone → Tailwind classes lookup. Used by
// SectionHeading + Dashboard hero stats + Catatan hero stats so the
// brand/accent/pop colour roles render identically wherever they
// appear. Extend with new tones (e.g. mood-coded) only here.

export type Tone = 'brand' | 'accent' | 'pop' | 'neutral';

export const ICON_TONE: Record<Tone, string> = {
    brand: 'bg-leaf/15 text-leaf-deep',
    accent: 'bg-horizon/15 text-horizon-deep',
    pop: 'bg-citrus/15 text-citrus-deep',
    neutral: 'bg-surface-sunken text-ink-2',
};
