import { lazy, Suspense, useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/cn';
import TemariMascot from './TemariMascot';
import type { Mood } from '@/types/inertia';

const LottiePlayer = lazy(() => import('./LottiePlayer'));

interface TemariLottieProps {
    mood: Mood;
    sigilPattern?: string;
    accessory?: string | null;
    /**
     * Lottie JSON URL (served from `/public`). Leave empty/null to use
     * the SVG mascot fallback — this is the default until a real rigged
     * asset is wired in.
     */
    src?: string | null;
    sizeClass?: string;
    sigilPixels?: number;
    className?: string;
}

/**
 * Optional Lottie-backed mascot. When `src` is set, dynamically loads
 * `lottie-react` and plays the JSON. When `src` is unset (current
 * default in prod), renders [[TemariMascot]] — so the runtime cost of
 * Lottie is zero until a real asset ships.
 *
 * Why a wrapper instead of replacing the SVG mascot wholesale: rigging
 * a 2D character requires a designer-built `.json` (After Effects +
 * Bodymovin) or Rive `.riv` file that this codebase can't generate.
 * Until that asset arrives, the SVG mascot is the canonical Temari and
 * this component is just a slot.
 *
 * Asset recommendations (when ready):
 *
 *   - LottieFiles.com — free + paid. Search "yarn ball", "blob
 *     character", "plushie mascot", "round mascot idle". Many CC0 /
 *     Lottie Community License options.
 *   - Adobe After Effects + Bodymovin plugin — self-author and export
 *     to a `.json` placed at [public/lottie/temari-idle.json].
 *   - Rive (rive.app) — web-based editor with a richer state-machine
 *     model. Costs an extra ~150KB of WASM if you switch to it later
 *     (`@rive-app/react-canvas`); revisit if/when richer interaction
 *     than this component supports is genuinely needed.
 *   - Commission via Fiverr / Upwork — typical 3-state idle/tap/wave
 *     rig runs $30-100. Hand the designer the Temari character brief
 *     in [CLAUDE.md] + a screenshot of the current SVG mascot.
 */
export default function TemariLottie({
    mood,
    sigilPattern = 'dddd',
    accessory = null,
    src = null,
    sizeClass = 'h-32 w-32',
    sigilPixels = 128,
    className,
}: Readonly<TemariLottieProps>) {
    const [data, setData] = useState<unknown | null>(null);
    const [errored, setErrored] = useState(false);
    const abortRef = useRef<AbortController | null>(null);

    useEffect(() => {
        if (src === null || src === undefined || src.length === 0) {
            setData(null);
            return;
        }
        abortRef.current?.abort();
        const ac = new AbortController();
        abortRef.current = ac;
        fetch(src, { signal: ac.signal })
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`Lottie fetch failed: ${r.status}`))))
            .then((json) => {
                // Guard against the resolved-after-unmount race: AbortController
                // aborts the network request but not in-flight .then chains.
                if (ac.signal.aborted) return;
                setData(json);
            })
            .catch((e) => {
                if (e.name !== 'AbortError') setErrored(true);
            });
        return () => ac.abort();
    }, [src]);

    if (src === null || src === undefined || src.length === 0 || errored || data === null) {
        return (
            <TemariMascot
                mood={mood}
                sigilPattern={sigilPattern}
                accessory={accessory}
                sizeClass={sizeClass}
                sigilPixels={sigilPixels}
                idle="mood"
                className={className}
            />
        );
    }

    return (
        <div className={cn('relative', sizeClass, className)}>
            <Suspense
                fallback={
                    <TemariMascot
                        mood={mood}
                        sigilPattern={sigilPattern}
                        accessory={accessory}
                        sizeClass={sizeClass}
                        sigilPixels={sigilPixels}
                    />
                }
            >
                <LottiePlayer animationData={data} />
            </Suspense>
        </div>
    );
}
