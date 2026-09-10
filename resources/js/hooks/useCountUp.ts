import { useEffect, useRef, useState } from 'react';

import { useReducedMotion } from '@/hooks/useReducedMotion';

const DEFAULT_DURATION_SECONDS = 0.9;

// Ease-out, no overshoot: a tallying number should settle exactly on its
// target rather than bounce past it the way a tier-1 press does.
const EASE_OUT_EXPO: [number, number, number, number] = [0.16, 1, 0.3, 1];

function cubicBezier(t: number, first: number, second: number): number {
    const inverse = 1 - t;
    return (
        3 * inverse * inverse * t * first +
        3 * inverse * t * t * second +
        t * t * t
    );
}

function ease(progress: number): number {
    if (progress >= 1) {
        return 1;
    }

    const [x1, y1, x2, y2] = EASE_OUT_EXPO;
    let low = 0;
    let high = 1;

    for (let i = 0; i < 12; i += 1) {
        const mid = (low + high) / 2;
        if (cubicBezier(mid, x1, x2) < progress) {
            low = mid;
        } else {
            high = mid;
        }
    }

    return cubicBezier((low + high) / 2, y1, y2);
}

/**
 * Ticks a displayed number up (or down) from its previous value to `target`
 * — the tier-2 "data reveal" convention for KPI tiles / stat displays, so a
 * number reads as tallying rather than snapping in. Starts from 0 on first
 * mount. Snaps straight to `target` under reduced motion.
 */
export function useCountUp(
    target: number,
    durationSeconds = DEFAULT_DURATION_SECONDS,
): number {
    const reducedMotion = useReducedMotion();
    const [tweened, setTweened] = useState(0);
    const previousTargetRef = useRef(0);

    useEffect(() => {
        if (reducedMotion) {
            previousTargetRef.current = target;
            return;
        }

        const from = previousTargetRef.current;
        previousTargetRef.current = target;

        const durationMs = durationSeconds * 1000;
        const startedAt = performance.now();
        let frame = requestAnimationFrame(function step() {
            const progress = Math.min(
                1,
                (performance.now() - startedAt) / durationMs,
            );
            setTweened(from + (target - from) * ease(progress));
            if (progress < 1) {
                frame = requestAnimationFrame(step);
            }
        });

        return () => cancelAnimationFrame(frame);
    }, [target, durationSeconds, reducedMotion]);

    return reducedMotion ? target : tweened;
}
