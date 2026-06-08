import { describe, expect, it } from 'vitest';
import { emberGlowStyle } from './styles';

describe('emberGlowStyle', () => {
    it('builds a radial gradient with the default intensity and falloff', () => {
        expect(emberGlowStyle()).toEqual({
            background:
                'radial-gradient(circle, rgba(232,160,118,0.3) 0%, transparent 70%)',
        });
    });

    it('threads the supplied intensity into the rgba alpha', () => {
        expect(emberGlowStyle(0.5)).toEqual({
            background:
                'radial-gradient(circle, rgba(232,160,118,0.5) 0%, transparent 70%)',
        });
    });

    it('threads a custom falloff stop into the gradient', () => {
        expect(emberGlowStyle(0.2, '40%')).toEqual({
            background:
                'radial-gradient(circle, rgba(232,160,118,0.2) 0%, transparent 40%)',
        });
    });
});
