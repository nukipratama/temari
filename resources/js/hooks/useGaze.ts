import { useEffect, useRef, useState, type RefObject } from 'react';

interface Gaze {
    /** Horizontal offset in `[-1, 1]` (left → right). */
    x: number;
    /** Vertical offset in `[-1, 1]` (up → down). */
    y: number;
}

const ZERO: Gaze = { x: 0, y: 0 };

interface Options {
    /** Max distance (px) at which gaze still tracks fully. Beyond `falloff`, gaze drifts back toward center. */
    range?: number;
    /** Distance (px) past `range` over which the gaze fades to zero. */
    falloff?: number;
    /** When false, skip listener install + RAF entirely. Defaults true. */
    enabled?: boolean;
}

function canTrackGaze(): boolean {
    if (typeof globalThis.matchMedia !== 'function') return false;
    if (globalThis.matchMedia('(prefers-reduced-motion: reduce)').matches)
        return false;
    return globalThis.matchMedia('(pointer: fine)').matches;
}

/**
 * Tracks the cursor's position relative to the centre of the referenced
 * element and returns a normalised `[-1, 1]` gaze vector. Used to drive
 * mascot eye-tracking. Returns `{x: 0, y: 0}` on touch-only devices, when
 * the cursor is outside `range + falloff`, or when `prefers-reduced-motion`
 * is set.
 */
export function useGaze(
    ref: RefObject<HTMLElement | null>,
    options: Options = {},
): Gaze {
    const { range = 220, falloff = 160, enabled = true } = options;
    const [gaze, setGaze] = useState<Gaze>(ZERO);
    const rafRef = useRef(0);
    const latestRef = useRef<MouseEvent | null>(null);

    useEffect(() => {
        if (!enabled || !canTrackGaze()) return;
        const el = ref.current;
        if (el === null) return;

        const tick = () => {
            const e = latestRef.current;
            if (e === null) return;
            const rect = el.getBoundingClientRect();
            const cx = rect.left + rect.width / 2;
            const cy = rect.top + rect.height / 2;
            const dx = e.clientX - cx;
            const dy = e.clientY - cy;
            const dist = Math.hypot(dx, dy);
            if (dist === 0) {
                setGaze((prev) => (prev.x === 0 && prev.y === 0 ? prev : ZERO));
                return;
            }

            const strength =
                dist > range ? Math.max(0, 1 - (dist - range) / falloff) : 1;
            // Quantize to 2 decimals so sub-pixel cursor jitter doesn't trigger
            // a re-render of the whole mascot tree on each mousemove.
            const nx = Math.round((dx / dist) * strength * 100) / 100;
            const ny = Math.round((dy / dist) * strength * 100) / 100;
            setGaze((prev) =>
                prev.x === nx && prev.y === ny ? prev : { x: nx, y: ny },
            );
        };

        const onMove = (e: MouseEvent) => {
            latestRef.current = e;
            cancelAnimationFrame(rafRef.current);
            rafRef.current = requestAnimationFrame(tick);
        };

        document.addEventListener('mousemove', onMove, { passive: true });
        return () => {
            cancelAnimationFrame(rafRef.current);
            document.removeEventListener('mousemove', onMove);
        };
    }, [ref, range, falloff, enabled]);

    return gaze;
}
