import { act, renderHook } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { makeUser, setMockPage } from '@/test/setup';

import { useDemoGuard } from './useDemoGuard';

describe('useDemoGuard', () => {
    it('runs the action straight away for a non-demo user', () => {
        setMockPage({ auth: { user: makeUser({ is_demo: false }) } });
        const { result } = renderHook(() => useDemoGuard());
        const action = vi.fn();

        act(() => result.current.guard(action));

        expect(action).toHaveBeenCalledOnce();
        expect(result.current.open).toBe(false);
        expect(result.current.isDemo).toBe(false);
    });

    it('opens the modal and does not run the action for a demo user', () => {
        setMockPage({ auth: { user: makeUser({ is_demo: true }) } });
        const { result } = renderHook(() => useDemoGuard());
        const action = vi.fn();

        act(() => result.current.guard(action));

        expect(action).not.toHaveBeenCalled();
        expect(result.current.open).toBe(true);
        expect(result.current.isDemo).toBe(true);
    });

    it('defaults isDemo to false when there is no auth user', () => {
        setMockPage({ auth: { user: null } });
        const { result } = renderHook(() => useDemoGuard());

        expect(result.current.isDemo).toBe(false);
    });
});
