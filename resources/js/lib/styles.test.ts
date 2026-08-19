import { describe, expect, it } from 'vitest';

import {
    GLOW_COLORS,
    dawnRayStyle,
    glowStyle,
    noiseFilterStyle,
} from './styles';

describe('glowStyle', () => {
    it.each(Object.entries(GLOW_COLORS))(
        'builds a radial gradient from the %s glow tuple',
        (_name, { r, g, b }) => {
            expect(glowStyle(r, g, b).background).toBe(
                `radial-gradient(circle, rgba(${r},${g},${b},0.3) 0%, transparent 70%)`,
            );
        },
    );

    it('threads the supplied intensity and falloff into the gradient', () => {
        const { r, g, b } = GLOW_COLORS.horizon;
        expect(glowStyle(r, g, b, 0.5, '40%').background).toBe(
            `radial-gradient(circle, rgba(${r},${g},${b},0.5) 0%, transparent 40%)`,
        );
    });
});

describe('noiseFilterStyle', () => {
    it('inlines the turbulence filter, so the grain costs no asset request', () => {
        expect(noiseFilterStyle().backgroundImage).toContain('feTurbulence');
        expect(noiseFilterStyle().backgroundSize).toBe('128px 128px');
    });
});

describe('dawnRayStyle', () => {
    it('sweeps the ray bottom-left to top-right at 160deg', () => {
        expect(dawnRayStyle().background).toContain('linear-gradient(160deg');
    });
});
