import type { CSSProperties } from 'react';

// Ember-orange radial glow used as a decorative atmospheric backdrop behind
// hero panels. Callers handle absolute positioning + size; this only owns
// the rgba/falloff formula so the magic number isn't pasted at every site.
export function emberGlowStyle(intensity = 0.3, falloff = '70%'): CSSProperties {
    return {
        background: `radial-gradient(circle, rgba(232,160,118,${intensity}) 0%, transparent ${falloff})`,
    };
}
