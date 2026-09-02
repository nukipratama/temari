import { useEffect } from 'react';

const STORAGE_KEY = 'temari-theme';

/**
 * Mirrors app.blade.php's blocking inline script, but live. That script only
 * resolves `data-theme` once, before first paint — a tab left open in
 * 'system' mode across an OS theme change would otherwise stay on whatever
 * resolved at load until the next full reload. Only 'system' triggers a live
 * update here; an explicit 'light'/'dark' choice is never overridden by the
 * OS while the tab is open.
 */
export function useSystemTheme(): void {
    useEffect(() => {
        const media = window.matchMedia('(prefers-color-scheme: dark)');

        function applyIfSystem() {
            let stored: string | null = null;
            try {
                stored = localStorage.getItem(STORAGE_KEY);
            } catch {
                return;
            }
            if (stored !== 'system') return;
            const resolved = media.matches ? 'dark' : 'light';
            document.documentElement.dataset.theme = resolved;
            document.documentElement.style.colorScheme = resolved;
        }

        media.addEventListener('change', applyIfSystem);
        return () => media.removeEventListener('change', applyIfSystem);
    }, []);
}
