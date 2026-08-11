import { router } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { useEffect, useState } from 'react';

import { routeProgressBar } from '@/lib/motion';

type Phase = 'idle' | 'loading' | 'done';

/**
 * Thin top-of-viewport bar for an in-flight full-page navigation — the
 * tier-1 route-transition affordance. A sibling of AppShell's <main>, never
 * a wrapper around it: that element is deliberately unkeyed (keying it once
 * caused 25 card remounts on Collection), so this animates its own element
 * instead of the content subtree.
 *
 * Gated on `visit.showProgress`, the same flag Inertia's request layer
 * already computes to separate a real navigation from a background/partial
 * reload (the `only`/`except`/`reset` props-only fetches used throughout
 * the app for AI-analysis polling and card-reveal refreshes) — those never
 * light the bar.
 */
export default function RouteProgressBar() {
    const [phase, setPhase] = useState<Phase>('idle');

    useEffect(() => {
        const offStart = router.on('start', (event) => {
            if (event.detail.visit.showProgress) {
                setPhase('loading');
            }
        });
        const offFinish = router.on('finish', (event) => {
            const { visit } = event.detail;
            if (!visit.showProgress) {
                return;
            }
            // A visit that was interrupted or cancelled (e.g. superseded by
            // a second navigation before it finished) still fires `finish`
            // — only a truly completed one earns the fill-and-fade "done"
            // flourish, matching Inertia's own bundled progress bar.
            setPhase(visit.completed ? 'done' : 'idle');
        });

        return () => {
            offStart();
            offFinish();
        };
    }, []);

    return (
        <motion.div
            aria-hidden
            data-testid="route-progress-bar"
            data-phase={phase}
            className="pointer-events-none fixed inset-x-0 top-0 z-50 h-[3px] origin-left bg-leaf"
            variants={routeProgressBar}
            initial="idle"
            animate={phase}
            onAnimationComplete={(label) => {
                if (label === 'done') {
                    setPhase('idle');
                }
            }}
        />
    );
}
