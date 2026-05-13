import type { SVGProps } from 'react';
import type { Mood } from '@/types/inertia';

interface TemariFaceProps extends SVGProps<SVGSVGElement> {
    mood: Mood;
    size?: number;
    color?: string;
    cheekColor?: string;
    /**
     * Eye-gaze offset in `[-1, 1]` per axis. Drives pupils toward the
     * cursor (see [[useGaze]]). Defaults to neutral.
     */
    gaze?: { x: number; y: number };
}

const EYE_X_LEFT = 36;
const EYE_X_RIGHT = 64;
const EYE_Y = 48;
const MOUTH_Y = 64;

const GAZE_PX = 2.4; // max pupil shift in viewBox units

function Eyes({ mood, color, gaze }: Readonly<{ mood: Mood; color: string; gaze: { x: number; y: number } }>) {
    const sw = 2;
    const dx = gaze.x * GAZE_PX;
    const dy = gaze.y * GAZE_PX;
    const looking = mood === 'glow' || mood === 'bouncy' || mood === 'dim';

    if (mood === 'glow') {
        return (
            <g fill={color} transform={looking ? `translate(${dx} ${dy})` : undefined}>
                {[EYE_X_LEFT, EYE_X_RIGHT].map((x) => (
                    <polygon
                        key={x}
                        points={`${x},${EYE_Y - 4} ${x + 1.2},${EYE_Y - 1.2} ${x + 4},${EYE_Y} ${x + 1.2},${EYE_Y + 1.2} ${x},${EYE_Y + 4} ${x - 1.2},${EYE_Y + 1.2} ${x - 4},${EYE_Y} ${x - 1.2},${EYE_Y - 1.2}`}
                    />
                ))}
            </g>
        );
    }
    if (mood === 'bouncy') {
        // Bouncy = closed-happy curves; track only horizontally so the
        // curve doesn't drift off-position vertically.
        return (
            <g fill="none" stroke={color} strokeWidth={sw} strokeLinecap="round" transform={`translate(${dx * 0.6} 0)`}>
                <path d={`M ${EYE_X_LEFT - 3.5} ${EYE_Y + 1.5} Q ${EYE_X_LEFT} ${EYE_Y - 3} ${EYE_X_LEFT + 3.5} ${EYE_Y + 1.5}`} />
                <path d={`M ${EYE_X_RIGHT - 3.5} ${EYE_Y + 1.5} Q ${EYE_X_RIGHT} ${EYE_Y - 3} ${EYE_X_RIGHT + 3.5} ${EYE_Y + 1.5}`} />
            </g>
        );
    }
    if (mood === 'wobble') {
        return (
            <g fill="none" stroke={color} strokeWidth={sw} strokeLinecap="round">
                <path d={`M ${EYE_X_LEFT - 3.5} ${EYE_Y - 0.5} Q ${EYE_X_LEFT} ${EYE_Y + 2.5} ${EYE_X_LEFT + 3.5} ${EYE_Y - 0.5}`} />
                <path d={`M ${EYE_X_RIGHT - 3.5} ${EYE_Y - 0.5} Q ${EYE_X_RIGHT} ${EYE_Y + 2.5} ${EYE_X_RIGHT + 3.5} ${EYE_Y - 0.5}`} />
            </g>
        );
    }
    if (mood === 'squished') {
        return (
            <g fill="none" stroke={color} strokeWidth={sw} strokeLinecap="round" strokeLinejoin="round">
                <polyline points={`${EYE_X_LEFT - 3},${EYE_Y - 3} ${EYE_X_LEFT},${EYE_Y} ${EYE_X_LEFT - 3},${EYE_Y + 3}`} />
                <polyline points={`${EYE_X_RIGHT + 3},${EYE_Y - 3} ${EYE_X_RIGHT},${EYE_Y} ${EYE_X_RIGHT + 3},${EYE_Y + 3}`} />
            </g>
        );
    }
    if (mood === 'spinning') {
        return (
            <g fill="none" stroke={color} strokeWidth={sw - 0.4} strokeLinecap="round">
                {[EYE_X_LEFT, EYE_X_RIGHT].map((x) => (
                    <path
                        key={x}
                        d={`M ${x} ${EYE_Y} m -3 0 a 3 3 0 1 0 6 0 a 3 3 0 1 0 -6 0 M ${x} ${EYE_Y} m -1.6 0 a 1.6 1.6 0 1 1 3.2 0`}
                    />
                ))}
            </g>
        );
    }
    // dim — sleepy half-eye line, drifts with gaze for "watching you" effect.
    return (
        <g stroke={color} strokeWidth={sw} strokeLinecap="round" transform={`translate(${dx * 0.8} ${dy * 0.4})`}>
            <line x1={EYE_X_LEFT - 3} y1={EYE_Y} x2={EYE_X_LEFT + 3} y2={EYE_Y} />
            <line x1={EYE_X_RIGHT - 3} y1={EYE_Y} x2={EYE_X_RIGHT + 3} y2={EYE_Y} />
        </g>
    );
}

function Mouth({ mood, color }: Readonly<{ mood: Mood; color: string }>) {
    const sw = 2;
    if (mood === 'glow' || mood === 'bouncy') {
        return (
            <path
                d={`M 44 ${MOUTH_Y - 0.5} Q 50 ${MOUTH_Y + 5} 56 ${MOUTH_Y - 0.5}`}
                fill="none"
                stroke={color}
                strokeWidth={sw}
                strokeLinecap="round"
            />
        );
    }
    if (mood === 'wobble') {
        return <ellipse cx={50} cy={MOUTH_Y + 1.5} rx={3} ry={2.6} fill="none" stroke={color} strokeWidth={sw} />;
    }
    if (mood === 'squished') {
        return (
            <path
                d={`M 43 ${MOUTH_Y} Q 46.5 ${MOUTH_Y - 2.2} 50 ${MOUTH_Y} T 57 ${MOUTH_Y}`}
                fill="none"
                stroke={color}
                strokeWidth={sw}
                strokeLinecap="round"
            />
        );
    }
    if (mood === 'spinning') {
        return (
            <polyline
                points={`43,${MOUTH_Y} 46.5,${MOUTH_Y - 1.5} 50,${MOUTH_Y + 1.5} 53.5,${MOUTH_Y - 1.5} 57,${MOUTH_Y}`}
                fill="none"
                stroke={color}
                strokeWidth={sw}
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        );
    }
    // dim
    return (
        <line
            x1={45.5}
            y1={MOUTH_Y + 1}
            x2={54.5}
            y2={MOUTH_Y + 1}
            stroke={color}
            strokeWidth={sw}
            strokeLinecap="round"
        />
    );
}

/**
 * Mood-driven stitched face. Six eye variants × six mouth variants so
 * each mood reads as a distinct expression rather than the same emoji
 * dropped in. Cheeks are always-on rose-tinted stitched dots — adds
 * warmth across the whole mood range. Stroke palette matches the sigil
 * so face + sigil read as one art style.
 *
 * `gaze` offset shifts pupils (or whole eye groups) toward the cursor.
 */
export default function TemariFace({
    mood,
    size = 144,
    color = 'currentColor',
    cheekColor = '#d96384',
    gaze = { x: 0, y: 0 },
    ...rest
}: Readonly<TemariFaceProps>) {
    return (
        <svg viewBox="0 0 100 100" width={size} height={size} aria-hidden {...rest}>
            <g fill={cheekColor} opacity={0.4}>
                <circle cx={26} cy={56} r={3.2} />
                <circle cx={74} cy={56} r={3.2} />
            </g>
            <Eyes mood={mood} color={color} gaze={gaze} />
            <Mouth mood={mood} color={color} />
        </svg>
    );
}
