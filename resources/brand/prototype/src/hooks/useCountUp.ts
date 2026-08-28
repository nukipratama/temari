import { animate } from 'framer-motion';
import { useEffect, useRef, useState } from 'react';

import { useReducedMotion } from '@/hooks/useReducedMotion';
import { countUpEase } from '@/lib/motion';

export function useCountUp(target: number, durationSeconds = 0.9): number {
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
