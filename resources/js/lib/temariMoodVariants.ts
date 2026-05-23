import type { Mood } from '@/types/inertia';

// Per-mood overrides for the Temari character SVG. Body + head + gear
// colours stay constant across moods so the creature is recognisable;
// mood is expressed via colour accents (headband/wristband/inner-ear/
// tail), face, pose, mood-specific accessory (medal / nightcap / etc.)
// and floating ambient particles (sparkles / hearts / droplets / ZZZ).

export type MoodAccessory =
    | 'medal'
    | 'flag'
    | 'towel'
    | 'bottle'
    | 'question'
    | 'nightcap'
    | null;

export type MoodParticles =
    | 'sparkles'
    | 'hearts'
    | 'droplets'
    | 'lines'
    | 'stars'
    | 'zzz'
    | null;

export interface MoodVariant {
    /** Hex for the headband, wristband, inner-ear, and tail pom-pom. */
    moodColor: string;
    eyes: 'open' | 'closed' | 'spiral' | 'squint' | 'wide' | 'shut';
    eyebrowLeft: string;
    eyebrowRight: string;
    mouthPath: string;
    earRotateLeft: number;
    earRotateRight: number;
    /** Pose deltas applied as SVG transforms to body / head. */
    bodyTranslateY: number;
    bodyRotate: number;
    bodyScaleY: number;
    headRotate: number;
    headTranslateY: number;
    /** Absolute arm rotation (deg) measured from rest (arms hanging). */
    armLeftRotate: number;
    armRightRotate: number;
    /** Mood-specific cosmetic accessory rendered on top of the character. */
    accessory: MoodAccessory;
    /** Mood-specific floating ambient particles drifting near the head. */
    particles: MoodParticles;
}

// Coordinates assume a 100x100 viewBox; head ~ y 16..52, eyes at y ≈ 33,
// eyebrows y ≈ 28, mouth y ≈ 42..46.
export const MOOD_VARIANTS: Record<Mood, MoodVariant> = {
    nyala: {
        moodColor: '#d99a1a',
        eyes: 'open',
        eyebrowLeft: 'M 34 28 Q 38 25 42 27',
        eyebrowRight: 'M 66 28 Q 62 25 58 27',
        mouthPath: 'M 43 44 Q 50 50 57 44',
        earRotateLeft: -12,
        earRotateRight: 12,
        bodyTranslateY: -2,
        bodyRotate: 0,
        bodyScaleY: 1,
        headRotate: -3,
        headTranslateY: -1,
        armLeftRotate: -110,
        armRightRotate: 110,
        accessory: 'medal',
        particles: 'sparkles',
    },
    enteng: {
        moodColor: '#c83a76',
        eyes: 'wide',
        eyebrowLeft: 'M 34 27 Q 38 23 42 26',
        eyebrowRight: 'M 66 27 Q 62 23 58 26',
        mouthPath: 'M 42 44 Q 46 51 50 47 Q 54 51 58 44',
        earRotateLeft: -10,
        earRotateRight: 24,
        bodyTranslateY: -7,
        bodyRotate: 6,
        bodyScaleY: 1,
        headRotate: 8,
        headTranslateY: -2,
        armLeftRotate: -70,
        armRightRotate: 35,
        accessory: 'flag',
        particles: 'hearts',
    },
    lemes: {
        moodColor: '#b8302f',
        eyes: 'squint',
        eyebrowLeft: 'M 34 29 Q 38 32 42 29',
        eyebrowRight: 'M 66 29 Q 62 32 58 29',
        mouthPath: 'M 42 46 Q 46 42 50 46 Q 54 42 58 46',
        earRotateLeft: 38,
        earRotateRight: -38,
        bodyTranslateY: 2,
        bodyRotate: -3,
        bodyScaleY: 1,
        headRotate: -7,
        headTranslateY: 1,
        armLeftRotate: 50,
        armRightRotate: -50,
        accessory: 'towel',
        particles: 'droplets',
    },
    oleng: {
        moodColor: '#c46f1c',
        eyes: 'shut',
        eyebrowLeft: 'M 34 30 Q 38 33 42 30',
        eyebrowRight: 'M 66 30 Q 62 33 58 30',
        mouthPath: 'M 44 45 Q 50 41 56 45',
        earRotateLeft: 30,
        earRotateRight: -30,
        bodyTranslateY: 4,
        bodyRotate: 0,
        bodyScaleY: 0.78,
        headRotate: 0,
        headTranslateY: 4,
        armLeftRotate: 90,
        armRightRotate: -90,
        accessory: 'bottle',
        particles: 'lines',
    },
    mumet: {
        moodColor: '#6b4ea8',
        eyes: 'spiral',
        eyebrowLeft: 'M 34 28 Q 38 26 42 28',
        eyebrowRight: 'M 66 28 Q 62 30 58 28',
        mouthPath: 'M 43 45 Q 46 43 50 45 Q 54 47 57 45',
        earRotateLeft: -18,
        earRotateRight: 30,
        bodyTranslateY: 0,
        bodyRotate: 12,
        bodyScaleY: 1,
        headRotate: 14,
        headTranslateY: 0,
        armLeftRotate: 20,
        armRightRotate: -20,
        accessory: 'question',
        particles: 'stars',
    },
    adem: {
        moodColor: '#6e7b72',
        eyes: 'closed',
        eyebrowLeft: 'M 34 29 Q 38 30 42 29',
        eyebrowRight: 'M 66 29 Q 62 30 58 29',
        mouthPath: 'M 46 45 L 54 45',
        earRotateLeft: 42,
        earRotateRight: -42,
        bodyTranslateY: 3,
        bodyRotate: 0,
        bodyScaleY: 0.93,
        headRotate: -2,
        headTranslateY: 3,
        armLeftRotate: 60,
        armRightRotate: -60,
        accessory: 'nightcap',
        particles: 'zzz',
    },
};

export function variantFor(mood: Mood): MoodVariant {
    return MOOD_VARIANTS[mood] ?? MOOD_VARIANTS.adem;
}
