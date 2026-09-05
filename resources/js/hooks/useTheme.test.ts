import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';

import { useTheme } from './useTheme';

function mockMatchMedia(matches: boolean) {
    window.matchMedia = ((query: string) => ({
        matches,
        media: query,
        addEventListener: () => {},
        removeEventListener: () => {},
    })) as unknown as typeof window.matchMedia;
}

describe('useTheme', () => {
    beforeEach(() => {
        mockMatchMedia(false);
        localStorage.clear();
        delete document.documentElement.dataset.theme;
        document.documentElement.style.colorScheme = '';
    });

    it('defaults to system when nothing is stored', () => {
        const { result } = renderHook(() => useTheme());
        expect(result.current.preference).toBe('system');
    });

    it('reads an already-stored preference on mount', () => {
        localStorage.setItem('temari-theme', 'light');
        const { result } = renderHook(() => useTheme());
        expect(result.current.preference).toBe('light');
    });

    it('falls back to system for a stale/unrecognized stored value', () => {
        localStorage.setItem('temari-theme', 'sepia');
        const { result } = renderHook(() => useTheme());
        expect(result.current.preference).toBe('system');
    });

    it('writes the chosen preference to localStorage', () => {
        const { result } = renderHook(() => useTheme());

        act(() => result.current.setTheme('light'));

        expect(localStorage.getItem('temari-theme')).toBe('light');
        expect(result.current.preference).toBe('light');
    });

    it('applies an explicit light/dark choice to the DOM immediately', () => {
        const { result } = renderHook(() => useTheme());

        act(() => result.current.setTheme('light'));

        expect(document.documentElement.dataset.theme).toBe('light');
        expect(document.documentElement.style.colorScheme).toBe('light');
    });

    it('resolves system against the current OS preference', () => {
        mockMatchMedia(true);
        const { result } = renderHook(() => useTheme());

        act(() => result.current.setTheme('system'));

        expect(localStorage.getItem('temari-theme')).toBe('system');
        expect(document.documentElement.dataset.theme).toBe('dark');
    });

    it('resolves system to light when the OS is not dark', () => {
        mockMatchMedia(false);
        const { result } = renderHook(() => useTheme());

        act(() => result.current.setTheme('system'));

        expect(document.documentElement.dataset.theme).toBe('light');
    });
});
