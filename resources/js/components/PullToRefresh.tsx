import { router } from '@inertiajs/react';
import { CircleAlert, LoaderCircle } from 'lucide-react';
import {
    type ReactNode,
    useCallback,
    useEffect,
    useRef,
    useState,
} from 'react';

import { Icon } from '@/components/ui/Icon';
import { PULL_THRESHOLD, usePullToRefresh } from '@/hooks/usePullToRefresh';
import { useReducedMotion } from '@/hooks/useReducedMotion';
import { cn } from '@/lib/cn';

const HOLD = 56;
const FAILURE_NOTICE_MS = 3000;

type Phase = 'idle' | 'refreshing' | 'failed';

interface PullToRefreshProps {
    children: ReactNode;
}

export default function PullToRefresh({
    children,
}: Readonly<PullToRefreshProps>) {
    const containerRef = useRef<HTMLDivElement>(null);
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
    const progress = Math.min(1, pull / PULL_THRESHOLD);

    const [previousSlide, setPreviousSlide] = useState(0);
    const [settling, setSettling] = useState(false);
    if (slide !== previousSlide) {
        setPreviousSlide(slide);
        setSettling(slide === 0 && previousSlide > 0);
    }

    return (
        <div ref={containerRef} className="relative">
            {(pulling || held) && (
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
                                className={cn(
                                    phase === 'refreshing' && 'animate-spin',
                                )}
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
            )}
            <div
                data-testid="pull-to-refresh-content"
                className={cn(
                    !pulling &&
                        !reducedMotion &&
                        'transition-transform duration-200 ease-out',
                )}
                style={
                    slide > 0 || settling
                        ? { transform: `translateY(${slide}px)` }
                        : undefined
                }
                onTransitionEnd={(event) => {
                    if (event.target === event.currentTarget) {
                        setSettling(false);
                    }
                }}
            >
                {children}
            </div>
        </div>
    );
}
