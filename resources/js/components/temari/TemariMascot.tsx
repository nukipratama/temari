import type { AnalysisPayload, Mood } from '@/types/inertia';

import { cn } from '@/lib/cn';

export type MascotPose = Mood | 'neutral' | 'sleepy' | 'thinking';

type Arc = readonly [from: number, to: number];
type Eyes =
    'dot' | 'happy' | 'content' | 'flat' | 'side' | 'woozy' | 'squeeze' | 'up';
type Brows = 'none' | 'worried';
type Mouth = 'none' | 'smile' | 'grin' | 'o' | 'flat' | 'wavy';

interface PoseSpec {
    outer: readonly Arc[];
    inner: readonly Arc[];
    tilt?: number;
    innerColor: string;
    dashedOuter?: boolean;
    eyes: Eyes;
    brows?: Brows;
    mouth: Mouth;
}

const OUTER_R = 37.5;
const INNER_R = 23;
const EYES_ONLY_BELOW = 32;
const FACE_ONLY_ZOOM = 100 / 40;

const moodInk = (mood: Mood) => `var(--color-mood-${mood}-ink)`;

export const POSES: Readonly<Record<MascotPose, PoseSpec>> = {
    neutral: {
        outer: [[0, 330]],
        inner: [[0, 240]],
        innerColor: 'var(--color-foreground)',
        eyes: 'dot',
        mouth: 'smile',
    },
    blazing: {
        outer: [[0, 352]],
        inner: [[0, 345]],
        innerColor: 'var(--color-mood-blazing)',
        eyes: 'happy',
        mouth: 'grin',
    },
    easy: {
        outer: [[0, 330]],
        inner: [[30, 210]],
        innerColor: moodInk('easy'),
        eyes: 'content',
        mouth: 'smile',
    },
    chill: {
        outer: [[0, 300]],
        inner: [[0, 180]],
        tilt: -12,
        innerColor: moodInk('chill'),
        eyes: 'side',
        mouth: 'smile',
    },
    wobbly: {
        outer: [[10, 330]],
        inner: [[-20, 220]],
        tilt: 8,
        innerColor: moodInk('wobbly'),
        eyes: 'woozy',
        mouth: 'wavy',
    },
    gassed: {
        outer: [[0, 190]],
        inner: [[0, 250]],
        innerColor: moodInk('gassed'),
        eyes: 'dot',
        brows: 'worried',
        mouth: 'o',
    },
    overloaded: {
        outer: [
            [0, 150],
            [185, 335],
        ],
        inner: [[0, 290]],
        innerColor: moodInk('overloaded'),
        eyes: 'squeeze',
        mouth: 'flat',
    },
    sleepy: {
        outer: [[0, 330]],
        inner: [[0, 200]],
        innerColor: moodInk('chill'),
        dashedOuter: true,
        eyes: 'flat',
        mouth: 'o',
    },
    thinking: {
        outer: [[0, 110]],
        inner: [[180, 290]],
        innerColor: 'var(--color-foreground)',
        eyes: 'up',
        mouth: 'none',
    },
};

const WRITING: ReadonlySet<AnalysisPayload['status']> = new Set([
    'queued',
    'processing',
]);

/** The thinking pose while any of a surface's narrated blocks is being written. */
export function writingPose(
    pose: MascotPose,
    ...blocks: ReadonlyArray<Pick<AnalysisPayload, 'status'> | undefined>
): MascotPose {
    return blocks.some((block) => block && WRITING.has(block.status))
        ? 'thinking'
        : pose;
}

function point(angle: number, r: number): string {
    const rad = (angle * Math.PI) / 180;

    return `${(50 + r * Math.sin(rad)).toFixed(2)} ${(50 - r * Math.cos(rad)).toFixed(2)}`;
}

export function arcPath([from, to]: Arc, r: number): string {
    const largeArc = to - from > 180 ? 1 : 0;

    return `M${point(from, r)} A${r} ${r} 0 ${largeArc} 1 ${point(to, r)}`;
}

function Dot(props: Readonly<{ cx: number; cy: number; r: number }>) {
    return <circle {...props} fill="currentColor" stroke="none" />;
}

function EyesShape({ eyes, bold }: Readonly<{ eyes: Eyes; bold: boolean }>) {
    const r = bold ? 5 : 3.4;
    const pair = (render: (x: number) => React.ReactNode) => (
        <>
            {render(43)}
            {render(57)}
        </>
    );

    switch (eyes) {
        case 'dot':
            return pair((x) => <Dot key={x} cx={x} cy={48} r={r} />);
        case 'up':
            return pair((x) => <Dot key={x} cx={x + 2} cy={45} r={r} />);
        case 'happy':
            return pair((x) => (
                <path key={x} d={`M${x - 3.5} 50 Q${x} 44.5 ${x + 3.5} 50`} />
            ));
        case 'content':
            return pair((x) => (
                <path key={x} d={`M${x - 3.5} 47 Q${x} 51.5 ${x + 3.5} 47`} />
            ));
        case 'flat':
            return pair((x) => (
                <path key={x} d={`M${x - 3.5} 49 L${x + 3.5} 49`} />
            ));
        case 'side':
            return pair((x) => <Dot key={x} cx={x + 2} cy={48} r={r} />);
        case 'woozy':
            return (
                <>
                    <Dot cx={43} cy={45.5} r={r} />
                    <Dot cx={57} cy={50.5} r={r} />
                </>
            );
        case 'squeeze':
            return (
                <path d="M39.5 45 L46 48 L39.5 51 M60.5 45 L54 48 L60.5 51" />
            );
    }
}

function BrowsShape({ brows }: Readonly<{ brows: Brows }>) {
    if (brows === 'worried') {
        return <path d="M38.5 43 L46 40.5 M61.5 43 L54 40.5" />;
    }

    return null;
}

function MouthShape({ mouth }: Readonly<{ mouth: Mouth }>) {
    switch (mouth) {
        case 'smile':
            return <path d="M45 56 Q50 60.5 55 56" />;
        case 'grin':
            return <path d="M44 55 Q50 63 56 55 Z" fill="currentColor" />;
        case 'o':
            return <Dot cx={50} cy={58} r={2.8} />;
        case 'flat':
            return <path d="M45.5 57.5 L54.5 57.5" />;
        case 'wavy':
            return <path d="M44 57.5 Q46.75 55 49.5 57.5 T55 57.5" />;
        case 'none':
            return null;
    }
}

interface TemariMascotProps {
    pose?: MascotPose;
    size?: number;
    /** A fixed-dark surface: resolves every token against the dark ground. */
    onSky?: boolean;
    /** Trace the arcs in once on mount, for the big-moment surfaces. */
    drawIn?: boolean;
    /** Just the face, cropped to fill the box, for a slot another ring already frames. */
    faceOnly?: boolean;
    className?: string;
}

/**
 * Temari as the living brand mark: the logo's two nested arcs, posed per mood,
 * with a face inside that drops to eyes only below 32px.
 */
export default function TemariMascot({
    pose = 'neutral',
    size = 48,
    onSky = false,
    drawIn = false,
    faceOnly = false,
    className,
}: Readonly<TemariMascotProps>) {
    const spec = POSES[pose];
    const renderedScale = faceOnly ? size * FACE_ONLY_ZOOM : size;
    const eyesOnly = renderedScale < EYES_ONLY_BELOW;
    const spinning = pose === 'thinking';

    return (
        <svg
            viewBox={faceOnly ? '30 30 40 40' : '0 0 100 100'}
            width={size}
            height={size}
            className={cn('flex-none', className)}
            aria-hidden="true"
            data-mascot={pose}
            data-theme={onSky ? 'dark' : undefined}
        >
            {!faceOnly && (
                <g
                    fill="none"
                    strokeWidth="11"
                    strokeLinecap="round"
                    transform={
                        spec.tilt ? `rotate(${spec.tilt} 50 50)` : undefined
                    }
                >
                    <g
                        stroke="var(--color-horizon)"
                        strokeDasharray={
                            spec.dashedOuter ? '0.1 17' : undefined
                        }
                        className={cn(spinning && 'mascot-spin')}
                        data-arc="outer"
                    >
                        {spec.outer.map((arc) => (
                            <path
                                key={arc.join()}
                                d={arcPath(arc, OUTER_R)}
                                pathLength={
                                    drawIn && !spec.dashedOuter ? 1 : undefined
                                }
                                className={cn(
                                    drawIn && !spec.dashedOuter && 'draw-in',
                                )}
                            />
                        ))}
                    </g>
                    <g
                        stroke={spec.innerColor}
                        className={cn(spinning && 'mascot-spin-reverse')}
                        data-arc="inner"
                    >
                        {spec.inner.map((arc) => (
                            <path
                                key={arc.join()}
                                d={arcPath(arc, INNER_R)}
                                pathLength={drawIn ? 1 : undefined}
                                className={cn(drawIn && 'draw-in')}
                                style={
                                    drawIn
                                        ? ({
                                              '--reveal-delay': '0.25s',
                                          } as React.CSSProperties)
                                        : undefined
                                }
                            />
                        ))}
                    </g>
                </g>
            )}
            <g
                color="var(--color-foreground)"
                fill="none"
                stroke="currentColor"
                strokeWidth={eyesOnly ? 4.5 : 3.2}
                strokeLinecap="round"
                strokeLinejoin="round"
                data-face={eyesOnly ? 'eyes' : 'full'}
            >
                <EyesShape eyes={spec.eyes} bold={eyesOnly} />
                {!eyesOnly && (
                    <>
                        <BrowsShape brows={spec.brows ?? 'none'} />
                        <MouthShape mouth={spec.mouth} />
                    </>
                )}
            </g>
        </svg>
    );
}
