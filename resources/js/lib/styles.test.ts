import { describe, expect, it } from 'vitest';

import { revealDelay } from './styles';

describe('revealDelay', () => {
    it('leads in at 0.05s and steps 0.06s per sibling', () => {
        const delay = (index: number) =>
            parseFloat(String(revealDelay(index)['--reveal-delay' as never]));

        expect(delay(0)).toBeCloseTo(0.05);
        expect(delay(2)).toBeCloseTo(0.17);
    });
});
