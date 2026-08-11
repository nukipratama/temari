import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';

import { makeUser, setMockPage } from '@/test/setup';

import { useCoachMark } from './useCoachMark';

beforeEach(() => {
    window.localStorage.clear();
    setMockPage({ auth: { user: makeUser({ id: 7 }) } });
});

describe('useCoachMark', () => {
    it('is visible by default', () => {
        const { result } = renderHook(() => useCoachMark('first-goal'));
        expect(result.current.visible).toBe(true);
    });

    it('hides once dismissed and persists that to localStorage', () => {
        const { result } = renderHook(() => useCoachMark('first-goal'));

        act(() => result.current.dismiss());

        expect(result.current.visible).toBe(false);
        expect(
            window.localStorage.getItem('temari:coachmark:7:first-goal'),
        ).toBe('1');
    });

    it('starts hidden on a later mount once dismissed', () => {
        window.localStorage.setItem('temari:coachmark:7:first-goal', '1');

        const { result } = renderHook(() => useCoachMark('first-goal'));

        expect(result.current.visible).toBe(false);
    });

    it('scopes dismissal per user id', () => {
        window.localStorage.setItem('temari:coachmark:7:first-goal', '1');
        setMockPage({ auth: { user: makeUser({ id: 42 }) } });

        const { result } = renderHook(() => useCoachMark('first-goal'));

        expect(result.current.visible).toBe(true);
    });

    it('keeps other coach-marks unaffected by a dismissal', () => {
        const { result } = renderHook(() => useCoachMark('first-goal'));
        act(() => result.current.dismiss());

        const { result: other } = renderHook(() => useCoachMark('second-mark'));
        expect(other.current.visible).toBe(true);
    });
});
