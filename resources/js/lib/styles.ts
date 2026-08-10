import type { CSSProperties } from 'react';

// Ember-orange radial glow used as a decorative atmospheric backdrop behind
// hero panels. Callers handle absolute positioning + size; this only owns
// the rgba/falloff formula so the magic number isn't pasted at every site.
export function emberGlowStyle(
    intensity = 0.3,
    falloff = '70%',
): CSSProperties {
    return {
        background: `radial-gradient(circle, rgba(217,165,60,${intensity}) 0%, transparent ${falloff})`,
    };
}

/** RGB tuples for the Threadwork glow palette. */
export const GLOW_COLORS = {
    ember: { r: 217, g: 165, b: 60 },
    leaf: { r: 107, g: 142, b: 111 },
    horizon: { r: 217, g: 165, b: 60 },
    sky: { r: 36, g: 28, b: 84 },
} as const;

/** Generic radial glow from an RGB color. */
export function glowStyle(
    r: number,
    g: number,
    b: number,
    intensity = 0.3,
    falloff = '70%',
): CSSProperties {
    return {
        background: `radial-gradient(circle, rgba(${r},${g},${b},${intensity}) 0%, transparent ${falloff})`,
    };
}

/** Subtle noise/grain texture overlay. Apply as an absolutely-positioned
 *  full-bleed span with `pointer-events-none`, `opacity-[0.035]`, and
 *  `mix-blend-overlay` for a premium film-grain feel. */
export function noiseFilterStyle(): CSSProperties {
    return {
        backgroundImage: `url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.65' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E")`,
        backgroundSize: '128px 128px',
    };
}

/** A soft diagonal light ray that sweeps from bottom-left to top-right,
 *  mimicking the first light of dawn breaking through the sky gradient. */
export function dawnRayStyle(): CSSProperties {
    return {
        background:
            'linear-gradient(160deg, transparent 25%, rgba(217,165,60,0.09) 42%, rgba(255,246,235,0.06) 50%, rgba(217,165,60,0.09) 58%, transparent 75%)',
    };
}
