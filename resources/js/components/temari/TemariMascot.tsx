import { useEffect, useRef, useState } from 'react';
import { motion, type Variants } from 'framer-motion';
import { Icon } from '@iconify/react';
import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/cn';
import { breath, FIDGET_PATTERNS, idleByMood, type FidgetPattern } from '@/lib/motion';
import { useGaze } from '@/hooks/useGaze';
import TemariCharacter from './TemariCharacter';
import type { Mood, SharedProps } from '@/types/inertia';

/**
 * Schedules an occasional micro-fidget — a randomly picked gesture (head shake,
 * tilt, hop, wiggle, pop) fired at 5-9s random intervals so the mascot's idle
 * loop reads as varied rather than a single repeated motion.
 */
function useIdleFidget(enabled: boolean): { tick: number; pattern: FidgetPattern | null } {
    const [state, setState] = useState<{ tick: number; pattern: FidgetPattern | null }>({
        tick: 0,
        pattern: null,
    });

    useEffect(() => {
        if (!enabled) return;
        let timer: number;
        const schedule = () => {
            const next = 5000 + Math.random() * 4000;
            timer = window.setTimeout(() => {
                setState((s) => ({
                    tick: s.tick + 1,
                    pattern: FIDGET_PATTERNS[Math.floor(Math.random() * FIDGET_PATTERNS.length)],
                }));
                schedule();
            }, next);
        };
        schedule();
        return () => window.clearTimeout(timer);
    }, [enabled]);

    return state;
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
    { icon: 'mdi:star-four-points', className: '-left-2 -top-1', size: 18, color: 'text-citrus', delay: 0 },
    { icon: 'mdi:star-four-points-outline', className: '-right-1 top-2', size: 14, color: 'text-horizon', delay: 0.4 },
    { icon: 'mdi:circle-small', className: 'right-2 -bottom-1', size: 22, color: 'text-leaf', delay: 0.8 },
    { icon: 'mdi:star-four-points-outline', className: 'left-0 -bottom-2', size: 12, color: 'text-citrus', delay: 1.2 },
    { icon: 'mdi:star-four-points', className: '-right-3 -top-2', size: 10, color: 'text-leaf', delay: 0.6 },
];

// Speed-line positions behind the mascot, signaling rightward motion. Stagger
// step is a multiplier on the mood's stagger duration.
const STREAK_LAYOUT: ReadonlyArray<{
    left: string;
    top: string;
    width: string;
    staggerStep: number;
}> = [
    { left: '4%', top: '42%', width: '24%', staggerStep: 0 },
    { left: '8%', top: '52%', width: '20%', staggerStep: 1 },
    { left: '2%', top: '62%', width: '28%', staggerStep: 2 },
    { left: '10%', top: '72%', width: '18%', staggerStep: 3 },
];

// Streak speed + opacity per mood — fast & bold for energetic states, slow &
// faint for tired ones. Matches the GAIT_BY_MOOD cadence in TemariCharacter.
type StreakConfig = { dur: string; stagger: string; peak: string };
const STREAK_DEFAULT: StreakConfig = { dur: '0.55s', stagger: '0.12s', peak: '0.55' };
function streakConfigFor(mood: Mood): StreakConfig {
    switch (mood) {
        case 'enteng':
            return { dur: '0.45s', stagger: '0.1s', peak: '0.7' };
        case 'nyala':
        case 'mumet':
            return STREAK_DEFAULT;
        case 'lemes':
            return { dur: '0.7s', stagger: '0.16s', peak: '0.5' };
        case 'adem':
            return { dur: '1.1s', stagger: '0.26s', peak: '0.32' };
        case 'oleng':
            return { dur: '1.3s', stagger: '0.3s', peak: '0.28' };
        default:
            return STREAK_DEFAULT;
    }
}

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
    // See TemariCharacter for why mascot motion isn't gated on reduced-motion.
    const reduced = false;
    const fidget = useIdleFidget(idle !== 'none');

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
    const streakCfg = streakConfigFor(mood);
    return (
        <motion.div
            ref={wrapperRef}
            aria-label={ariaLabel}
            className={cn('relative flex items-center justify-center', sizeClass, className)}
        >
            <div aria-hidden className="pointer-events-none absolute inset-0">
                {STREAK_LAYOUT.map((s) => (
                    <span
                        key={`${s.left}-${s.top}`}
                        className="temari-streak"
                        style={{
                            left: s.left,
                            top: s.top,
                            width: s.width,
                            animationDuration: streakCfg.dur,
                            animationDelay: `calc(${streakCfg.stagger} * ${s.staggerStep})`,
                            ['--streak-peak' as string]: streakCfg.peak,
                        }}
                    />
                ))}
            </div>
            <motion.div
                key={`fidget-${fidget.tick}`}
                className="absolute inset-0 flex items-center justify-center"
                animate={fidget.pattern ?? undefined}
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
