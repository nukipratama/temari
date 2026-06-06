import { lazy, Suspense, useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/cn';
import Temari from './Temari';
import { MOOD_TO_POSE } from '@/lib/temariPose';
import type { Mood } from '@/types/inertia';

const LottiePlayer = lazy(() => import('./LottiePlayer'));

interface TemariLottieProps {
    mood: Mood;
    src?: string | null;
    sizeClass?: string;
    className?: string;
}

// Falls back to the SVG Temari when `src` is unset — keeps the lottie-react
// bundle out of the graph until a real rigged asset ships.
export default function TemariLottie({
    mood,
    src = null,
    sizeClass = 'h-32 w-32',
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
                // AbortController cancels the request but not in-flight .then chains —
                // re-check aborted to avoid setState after unmount.
                if (ac.signal.aborted) return;
                setData(json);
            })
            .catch((e) => {
                if (e.name !== 'AbortError') setErrored(true);
            });
        return () => ac.abort();
    }, [src]);

    if (src === null || src === undefined || src.length === 0 || errored || data === null) {
        return <Temari pose={MOOD_TO_POSE[mood]} size={128} animate className={className} />;
    }

    return (
        <div className={cn('relative', sizeClass, className)}>
            <Suspense fallback={<Temari pose={MOOD_TO_POSE[mood]} size={128} />}>
                <LottiePlayer animationData={data} />
            </Suspense>
        </div>
    );
}
