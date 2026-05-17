import { memo, useEffect, useState } from 'react';
import { motion } from 'framer-motion';
import type { Mood } from '@/types/inertia';
import {
    type MoodAccessory,
    type MoodParticles,
    type MoodVariant,
    variantFor,
} from '@/lib/temariMoodVariants';
import { useReducedMotion } from '@/hooks/useReducedMotion';

interface TemariCharacterProps {
    mood: Mood;
    size?: number;
    /** Gaze offset in [-1, 1] for both axes — applied to eye pupils. */
    gaze?: { x: number; y: number };
    /** When true, idle animations (blink + wiggle + tail wag) are suppressed. */
    paused?: boolean;
    className?: string;
}

const BODY_COLOR = '#f0d9b0';
const BODY_SHADE = '#dcc18b';
const OUTLINE = '#3b2f1f';
const OUTLINE_W = 1.2;
const CHEEK_COLOR = '#e87a5e';
const INK_DARK = '#122218';
const INK_MED = '#3b4a40';
const TANK_COLOR = '#0e7a4c';
const TANK_TRIM = '#094d30';
const SHORTS_COLOR = '#07492d';
const SHORTS_STRIPE = '#d9764a';
const SNEAKER_COLOR = '#d9764a';
const SNEAKER_SHADE = '#b75f37';
const SNEAKER_SOLE = '#ffffff';
const SWEAT_COLOR = '#5e89b5';
const MEDAL_COLOR = '#e0a639';
const MEDAL_RIBBON = '#b8302f';
const TOWEL_COLOR = '#f5e6cc';
const HEART_COLOR = '#e87a5e';
const SPARKLE_COLOR = '#e0a639';
const QUESTION_COLOR = '#6b4ea8';
const GAZE_PX = 1.6;

const SHOULDER_LEFT = { x: 28, y: 55 };
const SHOULDER_RIGHT = { x: 72, y: 55 };
const EAR_LEFT_PIVOT = { x: 35, y: 19 };
const EAR_RIGHT_PIVOT = { x: 65, y: 19 };

function useBlinking(paused: boolean): boolean {
    const [closed, setClosed] = useState(false);

    useEffect(() => {
        if (paused) return;
        let cancelled = false;
        let timer: ReturnType<typeof setTimeout> | undefined;

        function scheduleNextBlink() {
            const delay = 2500 + Math.random() * 3500;
            timer = setTimeout(closeEye, delay);
        }
        function closeEye() {
            if (cancelled) return;
            setClosed(true);
            timer = setTimeout(openEye, 140);
        }
        function openEye() {
            if (cancelled) return;
            setClosed(false);
            scheduleNextBlink();
        }

        scheduleNextBlink();
        return () => {
            cancelled = true;
            if (timer) clearTimeout(timer);
        };
    }, [paused]);

    return closed;
}

function TemariCharacterImpl({
    mood,
    size = 100,
    gaze = { x: 0, y: 0 },
    paused = false,
    className,
}: Readonly<TemariCharacterProps>) {
    const v = variantFor(mood);
    const reduced = useReducedMotion();
    const motionOff = paused || reduced;
    const blinking = useBlinking(motionOff);

    const dx = gaze.x * GAZE_PX;
    const dy = gaze.y * GAZE_PX;

    const bodyTransform = `translate(0 ${v.bodyTranslateY}) rotate(${v.bodyRotate} 50 66) scale(1 ${v.bodyScaleY}) translate(0 ${(1 - v.bodyScaleY) * 66})`;
    const headTransform = `translate(0 ${v.headTranslateY}) rotate(${v.headRotate} 50 34)`;

    const breathAnim = motionOff
        ? { scaleY: 1 }
        : {
              scaleY: [1, 1.015, 1],
              transition: { duration: 3.4, repeat: Infinity, ease: 'easeInOut' as const },
          };

    const tailAnim = motionOff
        ? { rotate: 0 }
        : {
              rotate: [-10, 14, -10],
              transition: { duration: 2.2, repeat: Infinity, ease: 'easeInOut' as const },
          };

    return (
        <svg viewBox="0 0 100 100" width={size} height={size} className={className} aria-hidden>
            <ellipse cx={50} cy={95} rx={24} ry={2} fill={INK_DARK} opacity={0.14} />

            {/* Tail pom-pom — wagging */}
            <g transform="translate(73 62)">
                <motion.g animate={tailAnim} initial={false}>
                    <circle cx={0} cy={0} r={6} fill={v.moodColor} stroke={OUTLINE} strokeWidth={OUTLINE_W} />
                    <circle cx={-2} cy={-2} r={1.6} fill="#ffffff" opacity={0.55} />
                </motion.g>
            </g>

            {/* Sneakers */}
            <g stroke={OUTLINE} strokeWidth={OUTLINE_W} strokeLinejoin="round">
                <path
                    d="M 33 84 L 49 84 Q 50 87 49 90 Q 49 93 47 93 L 35 93 Q 32 93 32 90 Q 31 86 33 84 Z"
                    fill={SNEAKER_COLOR}
                />
                <path d="M 33 84 L 49 84 L 49 87 L 33 87 Z" fill={SNEAKER_SHADE} opacity={0.6} stroke="none" />
                <rect x={32} y={90} width={18} height={3} rx={1.4} fill={SNEAKER_SOLE} />
                <line x1={38} y1={87} x2={45} y2={87} stroke={SNEAKER_SOLE} strokeWidth={0.7} />
                <path
                    d="M 51 84 L 65 84 Q 69 86 68 90 Q 68 93 65 93 L 53 93 Q 51 93 51 90 Q 50 87 51 84 Z"
                    fill={SNEAKER_COLOR}
                />
                <path d="M 51 84 L 65 84 L 65 87 L 51 87 Z" fill={SNEAKER_SHADE} opacity={0.6} stroke="none" />
                <rect x={50} y={90} width={18} height={3} rx={1.4} fill={SNEAKER_SOLE} />
                <line x1={55} y1={87} x2={62} y2={87} stroke={SNEAKER_SOLE} strokeWidth={0.7} />
            </g>

            {/* Leg stubs */}
            <g stroke={OUTLINE} strokeWidth={OUTLINE_W}>
                <rect x={40} y={76} width={6} height={9} rx={2.5} fill={BODY_COLOR} />
                <rect x={54} y={76} width={6} height={9} rx={2.5} fill={BODY_COLOR} />
            </g>

            {/* Body group — per-mood transform, breath scales vertical */}
            <motion.g
                style={{ transformOrigin: '50px 80px', transformBox: 'fill-box' }}
                animate={breathAnim}
                initial={false}
            >
                <g transform={bodyTransform}>
                    <path
                        d="M 34 52 Q 30 64 32 76 Q 33 80 40 80 L 60 80 Q 67 80 68 76 Q 70 64 66 52 Z"
                        fill={BODY_COLOR}
                        stroke={OUTLINE}
                        strokeWidth={OUTLINE_W}
                        strokeLinejoin="round"
                    />
                    <path
                        d="M 32 65 Q 31 72 32 76 Q 33 80 40 80 L 60 80 Q 67 80 68 76 Q 69 72 68 65 Z"
                        fill={SHORTS_COLOR}
                        stroke={OUTLINE}
                        strokeWidth={OUTLINE_W}
                        strokeLinejoin="round"
                    />
                    <rect x={33} y={67} width={1.6} height={11} fill={SHORTS_STRIPE} />
                    <rect x={65.4} y={67} width={1.6} height={11} fill={SHORTS_STRIPE} />
                    <path
                        d="M 34 51 Q 33 57 32 65 L 68 65 Q 67 57 66 51 Q 60 54 50 54 Q 40 54 34 51 Z"
                        fill={TANK_COLOR}
                        stroke={OUTLINE}
                        strokeWidth={OUTLINE_W}
                        strokeLinejoin="round"
                    />
                    <path
                        d="M 42 51 Q 50 56 58 51"
                        stroke={TANK_TRIM}
                        strokeWidth={1.4}
                        fill="none"
                        strokeLinecap="round"
                    />
                    {/* Chest emblem with "T" stitching */}
                    <circle cx={50} cy={60} r={3} fill={v.moodColor} stroke={OUTLINE} strokeWidth={0.8} />
                    <text
                        x={50}
                        y={61.5}
                        textAnchor="middle"
                        fontSize={3.4}
                        fontWeight="bold"
                        fill="#ffffff"
                        fontFamily="sans-serif"
                    >
                        T
                    </text>
                </g>
            </motion.g>

            {/* Arms — rotated at the shoulder via SVG transform attribute */}
            <Arm side="left" rotate={v.armLeftRotate} moodColor={v.moodColor} />
            <Arm side="right" rotate={v.armRightRotate} moodColor={v.moodColor} />

            {/* === HEAD === */}
            <g transform={headTransform}>
                {/* Ears */}
                <Ear side="left" rotate={v.earRotateLeft} moodColor={v.moodColor} motionOff={motionOff} />
                <Ear side="right" rotate={v.earRotateRight} moodColor={v.moodColor} motionOff={motionOff} />

                {/* Head */}
                <rect
                    x={26}
                    y={16}
                    width={48}
                    height={36}
                    rx={17}
                    ry={16}
                    fill={BODY_COLOR}
                    stroke={OUTLINE}
                    strokeWidth={OUTLINE_W}
                />
                <path
                    d="M 50 16 Q 67 16 72 28 L 72 42 Q 67 50 50 50 Z"
                    fill={BODY_SHADE}
                    opacity={0.5}
                />

                {/* Headband + flag tail */}
                <rect
                    x={26}
                    y={22}
                    width={48}
                    height={5}
                    rx={2}
                    fill={v.moodColor}
                    stroke={OUTLINE}
                    strokeWidth={OUTLINE_W}
                />
                <circle cx={50} cy={24.5} r={1.1} fill="#ffffff" opacity={0.85} />
                <HeadbandFlag moodColor={v.moodColor} motionOff={motionOff} />

                {/* Cheeks */}
                <ellipse cx={33} cy={40} rx={3} ry={2.2} fill={CHEEK_COLOR} opacity={0.6} />
                <ellipse cx={67} cy={40} rx={3} ry={2.2} fill={CHEEK_COLOR} opacity={0.6} />

                {/* Eyebrows */}
                <path d={v.eyebrowLeft} stroke={INK_MED} strokeWidth={1.6} strokeLinecap="round" fill="none" />
                <path d={v.eyebrowRight} stroke={INK_MED} strokeWidth={1.6} strokeLinecap="round" fill="none" />

                {/* Eyes — blink overrides variant when active */}
                <Eyes variant={blinking ? 'closed' : v.eyes} gazeX={dx} gazeY={dy} />

                {/* Mouth */}
                <path d={v.mouthPath} stroke={INK_MED} strokeWidth={1.6} strokeLinecap="round" fill="none" />
            </g>

            <Accessory kind={v.accessory} moodColor={v.moodColor} />
            <Particles kind={v.particles} moodColor={v.moodColor} motionOff={motionOff} />
        </svg>
    );
}

// === Arm ============================================================

interface ArmProps {
    side: 'left' | 'right';
    rotate: number;
    moodColor: string;
}

function Arm({ side, rotate, moodColor }: Readonly<ArmProps>) {
    const pivot = side === 'left' ? SHOULDER_LEFT : SHOULDER_RIGHT;
    return (
        <g transform={`translate(${pivot.x} ${pivot.y}) rotate(${rotate})`}>
            <ellipse
                cx={0}
                cy={7}
                rx={5}
                ry={6.5}
                fill={BODY_COLOR}
                stroke={OUTLINE}
                strokeWidth={OUTLINE_W}
            />
            {side === 'left' && (
                <rect
                    x={-4.5}
                    y={9.5}
                    width={9}
                    height={2.6}
                    fill={moodColor}
                    stroke={OUTLINE}
                    strokeWidth={0.6}
                />
            )}
        </g>
    );
}

// === Ear ============================================================

interface EarProps {
    side: 'left' | 'right';
    rotate: number;
    moodColor: string;
    motionOff: boolean;
}

function Ear({ side, rotate, moodColor, motionOff }: Readonly<EarProps>) {
    const pivot = side === 'left' ? EAR_LEFT_PIVOT : EAR_RIGHT_PIVOT;
    const anim = motionOff
        ? { rotate }
        : {
              rotate: [rotate - 3, rotate + 3, rotate - 3],
              transition: { duration: 4.2, repeat: Infinity, ease: 'easeInOut' as const, delay: side === 'right' ? 0.6 : 0 },
          };
    return (
        <g transform={`translate(${pivot.x} ${pivot.y})`}>
            <motion.g animate={anim} initial={false}>
                <ellipse
                    cx={0}
                    cy={-10}
                    rx={5}
                    ry={11}
                    fill={BODY_COLOR}
                    stroke={OUTLINE}
                    strokeWidth={OUTLINE_W}
                    strokeLinejoin="round"
                />
                <ellipse cx={0} cy={-9} rx={2.4} ry={7} fill={moodColor} opacity={0.55} />
            </motion.g>
        </g>
    );
}

// === Headband flag ===================================================

interface HeadbandFlagProps {
    moodColor: string;
    motionOff: boolean;
}

function HeadbandFlag({ moodColor, motionOff }: Readonly<HeadbandFlagProps>) {
    const anim = motionOff
        ? { rotate: 0 }
        : {
              rotate: [-4, 6, -4],
              transition: { duration: 2.8, repeat: Infinity, ease: 'easeInOut' as const },
          };
    // Anchored at the right end of the headband (x=74, y=24.5).
    return (
        <g transform="translate(74 24.5)">
            <motion.g animate={anim} initial={false}>
                <path
                    d="M 0 -2 L 9 -3 L 7 0 L 9 3 L 0 2 Z"
                    fill={moodColor}
                    stroke={OUTLINE}
                    strokeWidth={0.6}
                    strokeLinejoin="round"
                />
            </motion.g>
        </g>
    );
}

// === Eyes ============================================================

interface EyesProps {
    variant: MoodVariant['eyes'];
    gazeX: number;
    gazeY: number;
}

function Eyes({ variant, gazeX, gazeY }: Readonly<EyesProps>) {
    const sw = 1.5;
    const tracking = variant === 'open' || variant === 'wide';
    if (variant === 'closed') {
        return (
            <g fill="none" stroke={INK_DARK} strokeWidth={sw} strokeLinecap="round">
                <path d="M 33 34 Q 38 38 43 34" />
                <path d="M 57 34 Q 62 38 67 34" />
            </g>
        );
    }
    if (variant === 'shut') {
        return (
            <g fill="none" stroke={INK_DARK} strokeWidth={sw} strokeLinecap="round">
                <line x1={33} y1={34} x2={43} y2={34} />
                <line x1={57} y1={34} x2={67} y2={34} />
            </g>
        );
    }
    if (variant === 'squint') {
        return (
            <g fill="none" stroke={INK_DARK} strokeWidth={sw} strokeLinecap="round" strokeLinejoin="round">
                <polyline points="33,34 38,30 43,34" />
                <polyline points="57,34 62,30 67,34" />
            </g>
        );
    }
    if (variant === 'spiral') {
        return (
            <g fill="none" stroke={INK_DARK} strokeWidth={1.3} strokeLinecap="round">
                <path d="M 38 33 m -3.4 0 a 3.4 3.4 0 1 0 6.8 0 a 3.4 3.4 0 1 0 -6.8 0 M 38 33 m -1.7 0 a 1.7 1.7 0 1 1 3.4 0" />
                <path d="M 62 33 m -3.4 0 a 3.4 3.4 0 1 0 6.8 0 a 3.4 3.4 0 1 0 -6.8 0 M 62 33 m -1.7 0 a 1.7 1.7 0 1 1 3.4 0" />
            </g>
        );
    }
    const wide = variant === 'wide';
    const rx = wide ? 4.2 : 3.6;
    const ry = wide ? 4.8 : 4.2;
    const offX = tracking ? gazeX : 0;
    const offY = tracking ? gazeY : 0;
    return (
        <g>
            <ellipse cx={38 + offX} cy={33 + offY} rx={rx} ry={ry} fill={INK_DARK} />
            <ellipse cx={62 + offX} cy={33 + offY} rx={rx} ry={ry} fill={INK_DARK} />
            <circle cx={39.4 + offX} cy={31.5 + offY} r={wide ? 1.3 : 1.2} fill="#ffffff" />
            <circle cx={63.4 + offX} cy={31.5 + offY} r={wide ? 1.3 : 1.2} fill="#ffffff" />
        </g>
    );
}

// === Mood accessories =================================================

interface AccessoryProps {
    kind: MoodAccessory;
    moodColor: string;
}

function Accessory({ kind, moodColor }: Readonly<AccessoryProps>) {
    if (kind === 'medal') {
        // Gold medal on a red ribbon, pinned to the tank near the collar.
        return (
            <g>
                <path
                    d="M 56 51 L 60 60 L 56 62 Z"
                    fill={MEDAL_RIBBON}
                    stroke={OUTLINE}
                    strokeWidth={0.6}
                />
                <circle cx={58} cy={64} r={3.2} fill={MEDAL_COLOR} stroke={OUTLINE} strokeWidth={0.8} />
                <text
                    x={58}
                    y={65.5}
                    textAnchor="middle"
                    fontSize={3}
                    fontWeight="bold"
                    fill={OUTLINE}
                    fontFamily="sans-serif"
                >
                    1
                </text>
            </g>
        );
    }
    if (kind === 'nightcap') {
        // Pointed cap with a pom-pom on the tip, drooping to the right.
        return (
            <g>
                <path
                    d="M 28 18 Q 38 8 50 8 Q 64 8 72 16 Q 76 14 78 6 L 84 4 Q 82 12 76 18 Z"
                    fill={moodColor}
                    stroke={OUTLINE}
                    strokeWidth={OUTLINE_W}
                    strokeLinejoin="round"
                />
                <circle cx={84} cy={4} r={2.2} fill="#ffffff" stroke={OUTLINE} strokeWidth={0.6} />
                <rect
                    x={26}
                    y={16}
                    width={48}
                    height={3.5}
                    rx={1.5}
                    fill="#ffffff"
                    stroke={OUTLINE}
                    strokeWidth={0.6}
                />
            </g>
        );
    }
    if (kind === 'towel') {
        // Towel draped over both shoulders, hanging down the back.
        return (
            <g>
                <path
                    d="M 30 54 Q 50 49 70 54 L 68 62 Q 50 58 32 62 Z"
                    fill={TOWEL_COLOR}
                    stroke={OUTLINE}
                    strokeWidth={OUTLINE_W}
                    strokeLinejoin="round"
                />
                <line x1={36} y1={56} x2={36} y2={60} stroke={moodColor} strokeWidth={0.6} />
                <line x1={64} y1={56} x2={64} y2={60} stroke={moodColor} strokeWidth={0.6} />
            </g>
        );
    }
    if (kind === 'bottle') {
        // Water bottle in the right hand (right side of body).
        return (
            <g transform="translate(78 70)">
                <rect
                    x={-2}
                    y={-1}
                    width={4}
                    height={2}
                    rx={0.5}
                    fill={OUTLINE}
                />
                <rect
                    x={-3}
                    y={1}
                    width={6}
                    height={9}
                    rx={1.2}
                    fill="#cbe3f0"
                    stroke={OUTLINE}
                    strokeWidth={OUTLINE_W}
                />
                <rect x={-2.4} y={4} width={4.8} height={4} fill={SWEAT_COLOR} opacity={0.5} />
            </g>
        );
    }
    if (kind === 'question') {
        // Big floating "?" above the head.
        return (
            <g transform="translate(50 4)">
                <circle cx={0} cy={2} r={5} fill="#ffffff" stroke={OUTLINE} strokeWidth={OUTLINE_W} />
                <text
                    x={0}
                    y={4}
                    textAnchor="middle"
                    fontSize={6.5}
                    fontWeight="bold"
                    fill={QUESTION_COLOR}
                    fontFamily="sans-serif"
                >
                    ?
                </text>
            </g>
        );
    }
    if (kind === 'flag') {
        // Small triangular flag held above the head.
        return (
            <g>
                <line x1={50} y1={2} x2={50} y2={16} stroke={OUTLINE} strokeWidth={0.8} />
                <path
                    d="M 50 2 L 62 5 L 50 9 Z"
                    fill={moodColor}
                    stroke={OUTLINE}
                    strokeWidth={0.6}
                    strokeLinejoin="round"
                />
            </g>
        );
    }
    /* v8 ignore next — every mood currently declares an accessory; the null fallback is here for the type. */
    return null;
}

// === Floating ambient particles =======================================

const SPARKLE_POSITIONS = [
    { x: 18, y: 14, r: 1.4, d: 0 },
    { x: 82, y: 12, r: 1.6, d: 0.5 },
    { x: 14, y: 30, r: 0.9, d: 1 },
    { x: 86, y: 28, r: 1, d: 1.4 },
] as const;

const HEART_POSITIONS = [
    { x: 20, y: 18, d: 0 },
    { x: 80, y: 14, d: 0.6 },
    { x: 84, y: 30, d: 1.2 },
] as const;

const STAR_POSITIONS = [
    { x: 22, y: 14, r: 1.8, d: 0 },
    { x: 78, y: 14, r: 1.8, d: 0.5 },
    { x: 16, y: 8, r: 1.1, d: 1 },
    { x: 84, y: 8, r: 1.1, d: 1.5 },
    { x: 50, y: 22, r: 1.1, d: 2 },
] as const;

interface ParticlesProps {
    kind: MoodParticles;
    moodColor: string;
    motionOff: boolean;
}

function Particles({ kind, moodColor, motionOff }: Readonly<ParticlesProps>) {
    if (kind === null) return null;

    const float = (delay: number, distance = 4) =>
        motionOff
            ? undefined
            : {
                  y: [0, -distance, 0],
                  opacity: [0.4, 1, 0.4],
                  transition: { duration: 2.4, repeat: Infinity, ease: 'easeInOut' as const, delay },
              };

    if (kind === 'sparkles') {
        return (
            <g fill={SPARKLE_COLOR}>
                {SPARKLE_POSITIONS.map((p) => (
                    <motion.g key={`${p.x}-${p.y}`} animate={float(p.d)} initial={false}>
                        <Sparkle cx={p.x} cy={p.y} r={p.r} />
                    </motion.g>
                ))}
            </g>
        );
    }
    if (kind === 'hearts') {
        return (
            <g>
                {HEART_POSITIONS.map((p) => (
                    <motion.g key={`${p.x}-${p.y}`} animate={float(p.d, 5)} initial={false}>
                        <Heart cx={p.x} cy={p.y} fill={HEART_COLOR} />
                    </motion.g>
                ))}
            </g>
        );
    }
    if (kind === 'droplets') {
        return (
            <g fill={SWEAT_COLOR} stroke={OUTLINE} strokeWidth={0.5}>
                <path d="M 22 32 Q 18 37 22 40 Q 26 37 22 32 Z" />
                <path d="M 28 22 Q 25 26 28 28 Q 31 26 28 22 Z" opacity={0.85} />
                <motion.path
                    d="M 14 44 Q 11 48 14 50 Q 17 48 14 44 Z"
                    animate={float(0.4, 6)}
                    initial={false}
                />
                <motion.path
                    d="M 86 46 Q 83 50 86 52 Q 89 50 86 46 Z"
                    animate={float(1, 6)}
                    initial={false}
                />
            </g>
        );
    }
    if (kind === 'lines') {
        // Speed/squish lines on either side of the body.
        return (
            <g stroke={OUTLINE} strokeWidth={1.2} strokeLinecap="round" opacity={0.7}>
                <line x1={22} y1={64} x2={28} y2={64} />
                <line x1={22} y1={70} x2={28} y2={70} />
                <line x1={72} y1={64} x2={78} y2={64} />
                <line x1={72} y1={70} x2={78} y2={70} />
            </g>
        );
    }
    if (kind === 'stars') {
        return (
            <g fill={moodColor}>
                {STAR_POSITIONS.map((p) => (
                    <motion.circle
                        key={`${p.x}-${p.y}`}
                        cx={p.x}
                        cy={p.y}
                        r={p.r}
                        animate={float(p.d, 3)}
                        initial={false}
                    />
                ))}
            </g>
        );
    }
    if (kind === 'zzz') {
        // ZZZ trail floating up to the upper-right.
        return (
            <g fill={INK_MED} fontFamily="sans-serif" fontWeight="bold">
                <motion.text x={78} y={22} fontSize={6} animate={float(0, 3)} initial={false}>
                    Z
                </motion.text>
                <motion.text x={84} y={14} fontSize={5} animate={float(0.6, 3)} initial={false}>
                    Z
                </motion.text>
                <motion.text x={88} y={8} fontSize={4} animate={float(1.2, 3)} initial={false}>
                    z
                </motion.text>
            </g>
        );
    }
    /* v8 ignore next — every mood currently declares particles; null fallback is here for the type. */
    return null;
}

function Sparkle({ cx, cy, r }: Readonly<{ cx: number; cy: number; r: number }>) {
    // 4-point sparkle drawn as two thin overlapping diamonds.
    const d = `M ${cx} ${cy - r * 2} L ${cx + r * 0.5} ${cy} L ${cx} ${cy + r * 2} L ${cx - r * 0.5} ${cy} Z M ${cx - r * 2} ${cy} L ${cx} ${cy - r * 0.5} L ${cx + r * 2} ${cy} L ${cx} ${cy + r * 0.5} Z`;
    return <path d={d} />;
}

function Heart({ cx, cy, fill }: Readonly<{ cx: number; cy: number; fill: string }>) {
    const path = `M ${cx} ${cy + 2} Q ${cx - 3} ${cy - 1} ${cx - 1.5} ${cy - 2.5} Q ${cx} ${cy - 1.5} ${cx} ${cy - 1} Q ${cx} ${cy - 1.5} ${cx + 1.5} ${cy - 2.5} Q ${cx + 3} ${cy - 1} ${cx} ${cy + 2} Z`;
    return <path d={path} fill={fill} stroke={OUTLINE} strokeWidth={0.4} />;
}

// Most props are primitives. `gaze` is the only object — useGaze already
// quantizes to 2 decimals so equality is stable across mousemoves where
// the cursor barely shifts. Re-rendered by mood / size / paused changes,
// or when the gaze pair actually changes value.
const TemariCharacter = memo(TemariCharacterImpl, (prev, next) => {
    if (prev.mood !== next.mood) return false;
    if (prev.size !== next.size) return false;
    if (prev.paused !== next.paused) return false;
    if (prev.className !== next.className) return false;
    const pg = prev.gaze ?? { x: 0, y: 0 };
    const ng = next.gaze ?? { x: 0, y: 0 };
    return pg.x === ng.x && pg.y === ng.y;
});

export default TemariCharacter;
