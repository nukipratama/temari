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
}

/**
 * Tracks the cursor's position relative to the centre of the referenced
 * element and returns a normalised `[-1, 1]` gaze vector. Used to drive
 * mascot eye-tracking. Returns `{x: 0, y: 0}` on touch-only devices, when
 * the cursor is outside `range + falloff`, or when `prefers-reduced-motion`
 * is set.
 *
 * Listens at the document level so the gaze updates even when the mouse is
 * not over the mascot — that's the whole point (eyes turn *toward* the
 * cursor as it approaches).
 */
export function useGaze(ref: RefObject<HTMLElement | null>, options: Options = {}): Gaze {
    const { range = 220, falloff = 160 } = options;
    const [gaze, setGaze] = useState<Gaze>(ZERO);
    const rafRef = useRef(0);
    const latestRef = useRef<MouseEvent | null>(null);

    useEffect(() => {
        if (typeof window === 'undefined') return;
        // Honour reduced-motion: no live tracking, stay neutral.
        if (globalThis.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return;
        // Pointer-fine check filters out touch-only devices where there's no
        // ambient cursor to track.
        if (!globalThis.matchMedia?.('(pointer: fine)').matches) return;

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

            let strength = 1;
            if (dist > range) strength = Math.max(0, 1 - (dist - range) / falloff);

            // Quantize to 2 decimals so sub-pixel cursor jitter doesn't
            // produce a unique `{x,y}` every frame — that was triggering a
            // re-render of the whole mascot tree on each mousemove.
            const nx = dist === 0 ? 0 : Math.round((dx / dist) * strength * 100) / 100;
            const ny = dist === 0 ? 0 : Math.round((dy / dist) * strength * 100) / 100;
            setGaze((prev) => (prev.x === nx && prev.y === ny ? prev : { x: nx, y: ny }));
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
    }, [ref, range, falloff]);

    return gaze;
}
