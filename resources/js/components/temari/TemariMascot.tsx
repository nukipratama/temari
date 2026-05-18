import { useEffect, useRef, useState } from 'react';
import { motion, type Variants } from 'framer-motion';
import { Icon } from '@iconify/react';
import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/cn';
import { breath, idleByMood } from '@/lib/motion';
import { useGaze } from '@/hooks/useGaze';
import { useReducedMotion } from '@/hooks/useReducedMotion';
import TemariCharacter from './TemariCharacter';
import type { Mood, SharedProps } from '@/types/inertia';

/**
 * Schedules an occasional micro-fidget — a small head-turn-and-back rotation
 * fired at 8-20s random intervals. Returns a counter that bumps on each
 * scheduled fire so Framer Motion picks up the new animate value.
 */
function useIdleFidget(enabled: boolean): number {
    const [tick, setTick] = useState(0);

    useEffect(() => {
        if (!enabled) return;
        let timer: number;
        const schedule = () => {
            const next = 8000 + Math.random() * 12000;
            timer = window.setTimeout(() => {
                setTick((n) => n + 1);
                schedule();
            }, next);
        };
        schedule();
        return () => window.clearTimeout(timer);
    }, [enabled]);

    return tick;
}

function resolveIdle(idle: 'none' | 'breath' | 'mood', mood: Mood): Variants | null {
    if (idle === 'none') return null;
    if (idle === 'breath') return breath;
    return idleByMood[mood] ?? breath;
}

interface TemariMascotProps {
    mood: Mood;
    sizeClass?: string;
    idle?: 'none' | 'breath' | 'mood';
    /** Pupils follow the cursor (range ~240px from mascot centre). */
    gazeTracking?: boolean;
    /** Override unlocked accessories. Falls back to shared Inertia prop when omitted. */
    unlockedAccessories?: ReadonlyArray<string>;
    /** Render unlocked accessory overlays from shared Inertia state. Default true. */
    showUnlocks?: boolean;
    /** Decorative sparkle ornaments around the mascot. */
    ornaments?: boolean;
    className?: string;
    'aria-label'?: string;
}

interface OrnamentSpec {
    icon: string;
    className: string;
    size: number;
    color: string;
    delay: number;
}

const ORNAMENT_LAYOUT: ReadonlyArray<OrnamentSpec> = [
    { icon: 'mdi:star-four-points', className: '-left-2 -top-1', size: 18, color: 'text-pop-500', delay: 0 },
    { icon: 'mdi:star-four-points-outline', className: '-right-1 top-2', size: 14, color: 'text-accent-500', delay: 0.4 },
    { icon: 'mdi:circle-small', className: 'right-2 -bottom-1', size: 22, color: 'text-brand-400', delay: 0.8 },
    { icon: 'mdi:star-four-points-outline', className: 'left-0 -bottom-2', size: 12, color: 'text-pop-400', delay: 1.2 },
    { icon: 'mdi:star-four-points', className: '-right-3 -top-2', size: 10, color: 'text-brand-500', delay: 0.6 },
];

export default function TemariMascot({
    mood,
    sizeClass = 'h-32 w-32',
    idle = 'none',
    gazeTracking = false,
    unlockedAccessories,
    showUnlocks = true,
    ornaments = false,
    className,
    'aria-label': ariaLabel,
}: Readonly<TemariMascotProps>) {
    const wrapperRef = useRef<HTMLDivElement>(null);
    const gaze = useGaze(wrapperRef, { range: 240, falloff: 200, enabled: gazeTracking });
    const reduced = useReducedMotion();
    const fidgetTick = useIdleFidget(!reduced && idle !== 'none');

    // usePage may be null in non-Inertia render contexts (e.g. Storybook).
    let shared: ReadonlyArray<string> = [];
    try {
        const page = usePage<SharedProps & { unlockedAccessories?: ReadonlyArray<string> }>();
        shared = page.props.unlockedAccessories ?? [];
    } catch {
        shared = [];
    }
    const unlocks: ReadonlyArray<string> = showUnlocks ? (unlockedAccessories ?? shared) : [];

    const idleVariants = resolveIdle(idle, mood);

    // Brief look-around fired by useIdleFidget at random intervals. The key
    // changes per tick so Framer Motion re-runs the keyframe animation each
    // time without us tracking timing manually.
    return (
        <motion.div
            ref={wrapperRef}
            aria-label={ariaLabel}
            className={cn('relative flex items-center justify-center', sizeClass, className)}
        >
            <motion.div
                key={`fidget-${fidgetTick}`}
                className="absolute inset-0 flex items-center justify-center"
                animate={fidgetTick > 0 ? { rotate: [0, -3, 4, -1, 0] } : undefined}
                transition={{ duration: 1.4, ease: 'easeInOut' }}
            >
                <motion.div
                    className="absolute inset-0 flex items-center justify-center"
                    variants={idleVariants ?? undefined}
                    animate={idleVariants === null ? undefined : 'idle'}
                >
                    <TemariCharacter
                        mood={mood}
                        gaze={gazeTracking ? gaze : { x: 0, y: 0 }}
                        unlockedAccessories={unlocks}
                        className="h-full w-full"
                    />
                </motion.div>
            </motion.div>
            {ornaments && !reduced && (
                <div aria-hidden className="pointer-events-none absolute inset-0">
                    {ORNAMENT_LAYOUT.map((spec) => (
                        <motion.span
                            key={`${spec.icon}-${spec.className}`}
                            className={cn('absolute', spec.className, spec.color)}
                            animate={{ scale: [0.85, 1.1, 0.85], opacity: [0.55, 1, 0.55] }}
                            transition={{
                                duration: 2.6,
                                repeat: Infinity,
                                ease: 'easeInOut',
                                delay: spec.delay,
                            }}
                        >
                            <Icon icon={spec.icon} width={spec.size} height={spec.size} />
                        </motion.span>
                    ))}
                </div>
            )}
        </motion.div>
    );
}
