import { useEffect, useState } from 'react';

/**
 * Whether a horizontal rail still has content past its right edge. The rails
 * this serves hide their scrollbar, so without a fade nothing tells a reader
 * the row continues.
 */
export function useScrollFade<T extends HTMLElement>() {
    const [rail, setRail] = useState<T | null>(null);
    const [faded, setFaded] = useState(false);

    useEffect(() => {
        if (!rail) {
            return;
        }
        const measure = () => {
            setFaded(rail.scrollWidth - rail.clientWidth - rail.scrollLeft > 1);
        };
        rail.addEventListener('scroll', measure, { passive: true });
        // observe() delivers an initial callback, which is the first measure —
        // calling it synchronously here would set state during the effect.
        const observer = new ResizeObserver(measure);
        observer.observe(rail);

        return () => {
            rail.removeEventListener('scroll', measure);
            observer.disconnect();
        };
    }, [rail]);

    return { ref: setRail, faded };
}

/** The mask that dissolves the rail's trailing edge while more remains. */
export const SCROLL_FADE_MASK =
    'linear-gradient(to right, #000 calc(100% - 40px), transparent)';
