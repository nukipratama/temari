import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useLastFilter } from './useLastFilter';

const KEY = 'temari:riwayat:last-filter';

beforeEach(() => {
    window.localStorage.clear();
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('useLastFilter', () => {
    it('offers nothing when there is no saved filter', () => {
        const { result } = renderHook(() => useLastFilter({}));
        expect(result.current.resumable).toBeNull();
    });

    it('offers a saved filter when the current view is unfiltered', () => {
        window.localStorage.setItem(KEY, JSON.stringify({ mood: 'nyala' }));
        const { result } = renderHook(() => useLastFilter({}));
        expect(result.current.resumable).toEqual({ mood: 'nyala' });
    });

    // Offering to resume a filter while one is already applied is just noise.
    it('offers nothing while a filter is already active', () => {
        window.localStorage.setItem(KEY, JSON.stringify({ mood: 'nyala' }));
        const { result } = renderHook(() => useLastFilter({ dist: '21up' }));
        expect(result.current.resumable).toBeNull();
    });

    it('remembers the filter the user is currently looking at', () => {
        renderHook(() => useLastFilter({ mood: 'lemes', dist: '0-5' }));
        expect(JSON.parse(window.localStorage.getItem(KEY)!)).toEqual({ mood: 'lemes', dist: '0-5' });
    });

    // Clearing filters is exactly when the previous one might be wanted back, so
    // an empty query must not overwrite the memory.
    it('does not erase the memory when the user clears their filters', () => {
        window.localStorage.setItem(KEY, JSON.stringify({ mood: 'nyala' }));
        renderHook(() => useLastFilter({}));
        expect(JSON.parse(window.localStorage.getItem(KEY)!)).toEqual({ mood: 'nyala' });
    });

    it('forgets on demand so the offer cannot nag', () => {
        window.localStorage.setItem(KEY, JSON.stringify({ mood: 'nyala' }));
        const { result } = renderHook(() => useLastFilter({}));

        act(() => result.current.forget());

        expect(result.current.resumable).toBeNull();
        expect(window.localStorage.getItem(KEY)).toBeNull();
    });

    it('ignores a corrupt saved value rather than breaking the page', () => {
        window.localStorage.setItem(KEY, 'not json{');
        const { result } = renderHook(() => useLastFilter({}));
        expect(result.current.resumable).toBeNull();
    });

    it('ignores a saved value of the wrong shape', () => {
        window.localStorage.setItem(KEY, JSON.stringify(['mood', 'nyala']));
        const { result } = renderHook(() => useLastFilter({}));
        expect(result.current.resumable).toBeNull();
    });

    it('drops non-string entries from a tampered value', () => {
        window.localStorage.setItem(KEY, JSON.stringify({ mood: 'nyala', evil: { a: 1 } }));
        const { result } = renderHook(() => useLastFilter({}));
        expect(result.current.resumable).toEqual({ mood: 'nyala' });
    });

    // Safari private mode throws on write; resuming is a nicety, never a crash.
    it('survives storage that throws on write', () => {
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('QuotaExceededError');
        });

        expect(() => renderHook(() => useLastFilter({ mood: 'nyala' }))).not.toThrow();
    });

    it('survives storage that throws on read', () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('SecurityError');
        });

        const { result } = renderHook(() => useLastFilter({}));
        expect(result.current.resumable).toBeNull();
    });
});
