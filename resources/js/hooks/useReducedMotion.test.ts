import { renderHook } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { useReducedMotion } from './useReducedMotion';

function stubMatchMedia(matches: boolean) {
    vi.stubGlobal(
        'matchMedia',
        vi.fn(() => ({
            matches,
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
        })),
    );
}

describe('useReducedMotion', () => {
    it('returns true when the user prefers reduced motion', () => {
        stubMatchMedia(true);
        const { result } = renderHook(() => useReducedMotion());
        expect(result.current).toBe(true);
    });

    it('returns false when the user prefers normal motion', () => {
        stubMatchMedia(false);
        const { result } = renderHook(() => useReducedMotion());
        expect(result.current).toBe(false);
    });

    it('subscribes to the query so a mid-session change is picked up', () => {
        const addEventListener = vi.fn();
        vi.stubGlobal(
            'matchMedia',
            vi.fn(() => ({
                matches: false,
                addEventListener,
                removeEventListener: vi.fn(),
            })),
        );
        renderHook(() => useReducedMotion());
        expect(addEventListener).toHaveBeenCalledWith(
            'change',
            expect.any(Function),
        );
    });
});
