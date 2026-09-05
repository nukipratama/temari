import { renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { useSystemTheme } from './useSystemTheme';

let changeListener: ((event: { matches: boolean }) => void) | null = null;
let matches = false;

function mockMatchMedia() {
    changeListener = null;
    window.matchMedia = vi.fn().mockImplementation((query: string) => ({
        matches,
        media: query,
        addEventListener: vi.fn(
            (event: string, listener: typeof changeListener) => {
                if (event === 'change') changeListener = listener;
            },
        ),
        removeEventListener: vi.fn(
            (event: string, listener: typeof changeListener) => {
                if (event === 'change' && changeListener === listener) {
                    changeListener = null;
                }
            },
        ),
    })) as unknown as typeof window.matchMedia;
}

function fireOsChange(nowMatches: boolean) {
    matches = nowMatches;
    changeListener?.({ matches: nowMatches });
}

describe('useSystemTheme', () => {
    beforeEach(() => {
        mockMatchMedia();
        document.documentElement.dataset.theme = 'dark';
        document.documentElement.style.colorScheme = 'dark';
        localStorage.clear();
    });

    afterEach(() => {
        delete document.documentElement.dataset.theme;
        document.documentElement.style.colorScheme = '';
    });

    it('does nothing on mount — only reacts to a later OS change', () => {
        localStorage.setItem('temari-theme', 'system');
        renderHook(() => useSystemTheme());

        expect(document.documentElement.dataset.theme).toBe('dark');
    });

    it('flips data-theme live when the stored preference is system', () => {
        localStorage.setItem('temari-theme', 'system');
        renderHook(() => useSystemTheme());

        fireOsChange(false);

        expect(document.documentElement.dataset.theme).toBe('light');
        expect(document.documentElement.style.colorScheme).toBe('light');
    });

    it('ignores an OS change when an explicit theme is stored', () => {
        localStorage.setItem('temari-theme', 'light');
        renderHook(() => useSystemTheme());

        fireOsChange(true);

        expect(document.documentElement.dataset.theme).toBe('dark');
    });

    it('ignores an OS change when nothing is stored', () => {
        renderHook(() => useSystemTheme());

        fireOsChange(true);

        expect(document.documentElement.dataset.theme).toBe('dark');
    });

    it('stops listening after unmount', () => {
        localStorage.setItem('temari-theme', 'system');
        const { unmount } = renderHook(() => useSystemTheme());
        unmount();

        fireOsChange(false);

        expect(document.documentElement.dataset.theme).toBe('dark');
    });
});
