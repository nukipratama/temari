import { useCallback, useState } from 'react';

const STORAGE_KEY = 'temari-theme';

export type ThemePreference = 'light' | 'dark' | 'system';

function isThemePreference(value: string | null): value is ThemePreference {
    return value === 'light' || value === 'dark' || value === 'system';
}

function readStored(): ThemePreference {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);
        return isThemePreference(stored) ? stored : 'dark';
    } catch {
        return 'dark';
    }
}

function resolve(preference: ThemePreference): 'light' | 'dark' {
    if (preference !== 'system') return preference;
    return window.matchMedia('(prefers-color-scheme: dark)').matches
        ? 'dark'
        : 'light';
}

/**
 * Owns the Settings appearance control's read/write side of the
 * 'temari-theme' key: mirrors app.blade.php's blocking-script resolution
 * order (an explicit light/dark wins, system follows the OS, anything else
 * falls back to dark) and applies a change to the DOM immediately so
 * switching is live with no reload. useSystemTheme (mounted once in
 * AppShell/BareShell) is the sibling that keeps 'system' mode live across a
 * later OS change while the tab stays open; both read/write the same key.
 */
export function useTheme() {
    const [preference, setPreference] = useState<ThemePreference>(readStored);

    const setTheme = useCallback((next: ThemePreference) => {
        setPreference(next);
        try {
            localStorage.setItem(STORAGE_KEY, next);
        } catch {
            // Storage can throw in a locked-down/private context; the DOM
            // still updates for this tab below, it just won't persist.
        }
        const resolved = resolve(next);
        document.documentElement.dataset.theme = resolved;
        document.documentElement.style.colorScheme = resolved;
    }, []);

    return { preference, setTheme };
}
