import { router } from '@inertiajs/react';
import { CircleAlert, LoaderCircle } from 'lucide-react';
import {
    type RefObject,
    useCallback,
    useEffect,
    useLayoutEffect,
    useState,
} from 'react';

import { Icon } from '@/components/ui/Icon';
import { PULL_THRESHOLD, usePullToRefresh } from '@/hooks/usePullToRefresh';
import { useReducedMotion } from '@/hooks/useReducedMotion';
import { cn } from '@/lib/cn';

const HOLD = 56;
const FAILURE_NOTICE_MS = 3000;

type Phase = 'idle' | 'refreshing' | 'failed';

export interface PullMotion {
    slide: number;
    animate: boolean;
}

interface PullToRefreshGestureProps {
    containerRef: RefObject<HTMLElement | null>;
    onMotion: (motion: PullMotion) => void;
}

export default function PullToRefreshGesture({
    containerRef,
    onMotion,
}: Readonly<PullToRefreshGestureProps>) {
    const reducedMotion = useReducedMotion();
    const [phase, setPhase] = useState<Phase>('idle');

    const refresh = useCallback(() => {
        let failed = false;
        setPhase('refreshing');
        router.reload({
            onHttpException: () => {
                failed = true;
                return false;
            },
            onNetworkError: () => {
                failed = true;
                return false;
            },
            onCancel: () => setPhase('idle'),
            onFinish: () => setPhase(failed ? 'failed' : 'idle'),
        });
    }, []);

    const { pull, pulling } = usePullToRefresh(
        containerRef,
        phase !== 'refreshing',
        refresh,
    );

    useEffect(() => {
        if (phase !== 'failed') {
            return;
        }
        const timer = setTimeout(() => setPhase('idle'), FAILURE_NOTICE_MS);
        return () => clearTimeout(timer);
    }, [phase]);

    const held = phase !== 'idle';
    let offset = held ? HOLD : 0;
    if (pulling) {
        offset = pull;
    }
    const slide = reducedMotion ? 0 : offset;
    const animate = !pulling && !reducedMotion;
    const progress = Math.min(1, pull / PULL_THRESHOLD);

    useLayoutEffect(() => {
        onMotion({ slide, animate });
    }, [onMotion, slide, animate]);

    if (!pulling && !held) {
        return null;
    }

    return (
        <div
            role="status"
            className="pointer-events-none absolute inset-x-0 top-0 flex items-center justify-center overflow-hidden"
            style={{ height: reducedMotion ? HOLD : offset }}
        >
            {phase === 'failed' ? (
                <p className="flex items-center gap-2 rounded-full bg-card px-3.5 py-1.5 font-sans text-sm text-ember-ink shadow-e1">
                    <Icon
                        icon={CircleAlert}
                        width={16}
                        height={16}
                        aria-hidden
                    />
                    couldn&apos;t refresh. pull to try again.
                </p>
            ) : (
                <span
                    className="rounded-full bg-card p-2 text-text-3 shadow-e1"
                    style={{ opacity: held ? 1 : progress }}
                >
                    <Icon
                        icon={LoaderCircle}
                        width={20}
                        height={20}
                        className={cn(phase === 'refreshing' && 'animate-spin')}
                        style={
                            phase === 'refreshing'
                                ? undefined
                                : { rotate: `${progress * 270}deg` }
                        }
                        aria-hidden
                    />
                    <span className="sr-only">
                        {phase === 'refreshing'
                            ? 'refreshing'
                            : 'pull to refresh'}
                    </span>
                </span>
            )}
        </div>
    );
}
