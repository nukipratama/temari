import { renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { useCountUp } from './useCountUp';

vi.mock('@/hooks/useReducedMotion', () => ({
    useReducedMotion: vi.fn(() => false),
}));

import { useReducedMotion } from '@/hooks/useReducedMotion';

describe('useCountUp', () => {
    beforeEach(() => {
        vi.mocked(useReducedMotion).mockReturnValue(false);
    });

    it('counts up from 0 to the target value', async () => {
        const { result } = renderHook(() => useCountUp(120));
        expect(result.current).toBe(0);
        await waitFor(() => expect(result.current).toBe(120));
    });

    it('tweens from the previous value to a new target when target changes', async () => {
        const { result, rerender } = renderHook(
            ({ target }) => useCountUp(target),
            { initialProps: { target: 50 } },
        );
        await waitFor(() => expect(result.current).toBe(50));

        rerender({ target: 80 });
        await waitFor(() => expect(result.current).toBe(80));
    });

    it('snaps straight to target with no animation under reduced motion', () => {
        vi.mocked(useReducedMotion).mockReturnValue(true);
        const { result } = renderHook(() => useCountUp(75));
        expect(result.current).toBe(75);
    });
});
