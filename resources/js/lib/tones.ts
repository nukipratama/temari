// Shared "icon tile" tone → Tailwind classes lookup. Used by
// SectionHeading + Dashboard hero stats + Catatan hero stats so the
// brand/accent/pop colour roles render identically wherever they
// appear. Extend with new tones (e.g. mood-coded) only here.

export type Tone = 'brand' | 'accent' | 'pop' | 'neutral';

export const ICON_TONE: Record<Tone, string> = {
    brand: 'bg-brand-100 text-brand-700',
    accent: 'bg-accent-100 text-accent-700',
    pop: 'bg-pop-100 text-pop-700',
    neutral: 'bg-surface-sunken text-ink-soft',
};
