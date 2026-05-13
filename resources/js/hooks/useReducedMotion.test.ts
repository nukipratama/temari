import { renderHook } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { useReducedMotion } from './useReducedMotion';

vi.mock('framer-motion', () => ({
    useReducedMotion: vi.fn(),
}));

import { useReducedMotion as useFmReducedMotion } from 'framer-motion';

describe('useReducedMotion', () => {
    it('returns true when FM hook reports user prefers reduced motion', () => {
        vi.mocked(useFmReducedMotion).mockReturnValue(true);
        const { result } = renderHook(() => useReducedMotion());
        expect(result.current).toBe(true);
    });

    it('returns false when FM hook reports user prefers normal motion', () => {
        vi.mocked(useFmReducedMotion).mockReturnValue(false);
        const { result } = renderHook(() => useReducedMotion());
        expect(result.current).toBe(false);
    });

    it('returns false when FM hook returns null (SSR / pre-mount)', () => {
        vi.mocked(useFmReducedMotion).mockReturnValue(null);
        const { result } = renderHook(() => useReducedMotion());
        expect(result.current).toBe(false);
    });
});
