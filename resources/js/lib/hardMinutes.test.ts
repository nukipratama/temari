import { describe, expect, it } from 'vitest';

import { formatHardMinutes } from './hardMinutes';

describe('formatHardMinutes', () => {
    it('shows whole minutes bare and the rest to one decimal', () => {
        expect(formatHardMinutes(0)).toBe('0');
        expect(formatHardMinutes(31.5)).toBe('31.5');
        expect(formatHardMinutes(27.66)).toBe('27.7');
    });
});
