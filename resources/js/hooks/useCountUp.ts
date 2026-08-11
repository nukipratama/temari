import { animate } from 'framer-motion';
import { useEffect, useRef, useState } from 'react';

import { useReducedMotion } from '@/hooks/useReducedMotion';
import { countUpEase } from '@/lib/motion';

const DEFAULT_DURATION_SECONDS = 0.9;

/**
 * Ticks a displayed number up (or down) from its previous value to `target`
 * — the tier-2 "data reveal" convention for KPI tiles / stat displays, so a
 * number reads as tallying rather than snapping in. Starts from 0 on first
 * mount. Snaps straight to `target` under reduced motion, matching the app-
 * wide `<MotionConfig reducedMotion="user">`.
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

        const controls = animate(from, target, {
            duration: durationSeconds,
            ease: countUpEase,
            onUpdate: setTweened,
        });

        return () => controls.stop();
    }, [target, durationSeconds, reducedMotion]);

    return reducedMotion ? target : tweened;
}
