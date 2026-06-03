import type { CSSProperties } from 'react';

import { cn } from '@/lib/cn';

export type TemariPose =
    | 'proud'
    | 'pumped'
    | 'excited'
    | 'holding'
    | 'reading'
    | 'wobble'
    | 'observational'
    | 'glow';

export interface TemariEquipped {
    headband?: 'ember' | 'epik' | 'legendaris' | null;
    medal?: 'pertama' | 'emas' | 'none';
    pita?: boolean;
    aura?: boolean;
}

export interface TemariProtoProps {
    pose?: TemariPose;
    size?: number;
    /** Cream-bg vs sky-bg surface — currently only swaps the cheek tone. */
    tone?: 'cream' | 'sky';
    equipped?: TemariEquipped | null;
    /** `true` = pose-driven animation, `false` = static, string = explicit CSS animation. */
    animate?: boolean | string;
    className?: string;
}

interface HeadbandPalette {
    band: string;
    accent: string;
}

const HEADBAND_PALETTE: Record<NonNullable<TemariEquipped['headband']>, HeadbandPalette> = {
    ember: { band: '#C4623F', accent: '#A8512C' },
    epik: { band: '#7B5BB6', accent: '#5E4490' },
    legendaris: { band: '#D9B23A', accent: '#B8941E' },
};

const MEDAL_PALETTE = {
    pertama: { coin: '#C77F4A', glow: '#E0A06E' },
    emas: { coin: '#D9B23A', glow: '#F5D365' },
} as const;

const EAR_TILT: Record<TemariPose, [number, number]> = {
    proud: [-8, 8],
    pumped: [-14, 14],
    excited: [-22, 22],
    holding: [-10, 10],
    reading: [-4, 18],
    wobble: [10, -10],
    observational: [-6, 6],
    glow: [-10, 10],
};

type EyeShape = 'normal' | 'big' | 'side' | 'sad';
type MouthShape = 'smile' | 'open' | 'small' | 'frown';

const EYE_BY_POSE: Record<TemariPose, EyeShape> = {
    proud: 'normal',
    pumped: 'big',
    excited: 'big',
    holding: 'normal',
    reading: 'side',
    wobble: 'sad',
    observational: 'normal',
    glow: 'big',
};

const MOUTH_BY_POSE: Record<TemariPose, MouthShape> = {
    proud: 'smile',
    pumped: 'open',
    excited: 'open',
    holding: 'smile',
    reading: 'small',
    wobble: 'frown',
    observational: 'smile',
    glow: 'smile',
};

const POSE_ANIM: Record<TemariPose, string> = {
    proud: 'temari-bob 4s ease-in-out infinite',
    pumped: 'temari-bounce 1.4s ease-in-out infinite',
    excited: 'temari-bounce 0.9s ease-in-out infinite',
    holding: 'temari-bob 4s ease-in-out infinite',
    reading: 'temari-tilt 3.5s ease-in-out infinite',
    wobble: 'temari-sway 2.4s ease-in-out infinite',
    observational: 'temari-nod 3.6s ease-in-out infinite',
    glow: 'temari-bob 3.2s ease-in-out infinite, temari-breathe 3.2s ease-in-out infinite',
};

const DEFAULT_MEDAL_POSES = new Set<TemariPose>(['proud', 'pumped', 'holding', 'observational', 'glow']);
const SPARKLE_POSES = new Set<TemariPose>(['pumped', 'excited', 'glow']);

const FUR = '#F2DAB6';
const FUR_SHADE = '#DCC097';
const INNER_EAR = '#E8A076';
const EYE = '#1A1812';
const CHEEK = '#E89B8E';

export default function TemariProto({
    pose = 'proud',
    size = 140,
    tone = 'cream',
    equipped = null,
    animate = false,
    className,
}: Readonly<TemariProtoProps>) {
    // viewBox tightly + evenly frames the artwork with ~5px of margin on every
    // side: ear tips reach y≈-4, the medal/pita reach y≈121, and the aura/sparkles
    // reach x≈5 and x≈115. So the box runs x[0..120], y[-9..126] (height 135).
    // The old 0/0/120/150 clipped the ears at the top and left a dead strip below.
    const aspectHeight = (size * 135) / 120;
    const headbandKey = equipped?.headband ?? 'ember';
    const hb = HEADBAND_PALETTE[headbandKey];

    const medalKey = resolveMedalKey(pose, equipped);
    const medal = medalKey ? MEDAL_PALETTE[medalKey] : null;

    const showAura = equipped?.aura === true;
    const showSparkle = SPARKLE_POSES.has(pose) || showAura;
    const showPita = equipped?.pita === true;
    const earTilt = EAR_TILT[pose];
    const eyeShape = EYE_BY_POSE[pose];
    const mouthShape = MOUTH_BY_POSE[pose];

    let rootAnim: CSSProperties['animation'] = 'none';
    if (animate !== false) {
        rootAnim = typeof animate === 'string' ? animate : POSE_ANIM[pose];
    }

    return (
        <div
            className={cn('temari-root', className)}
            style={{ animation: rootAnim, width: size, height: aspectHeight }}
            data-pose={pose}
            data-tone={tone}
        >
            <svg
                viewBox="0 -9 120 135"
                width={size}
                height={aspectHeight}
                style={{ display: 'block', overflow: 'visible' }}
                aria-hidden
            >
                {showAura && <Aura />}
                <Ears tilt={earTilt} />
                <ellipse cx="60" cy="60" rx="34" ry="32" fill={FUR} stroke={FUR_SHADE} strokeWidth="1.2" />
                <Headband band={hb.band} legendary={headbandKey === 'legendaris'} />
                <Cheeks />
                <Eyes shape={eyeShape} />
                <ellipse cx="60" cy="74" rx="2.5" ry="2" fill={hb.band} />
                <Mouth shape={mouthShape} />
                {showPita && <Pita />}
                {medal && <Medal coin={medal.coin} glow={medal.glow} ringGlow={medalKey === 'emas'} />}
                {showSparkle && <Sparkles innerEarHex={INNER_EAR} />}
            </svg>
        </div>
    );
}

function resolveMedalKey(pose: TemariPose, equipped: TemariEquipped | null): keyof typeof MEDAL_PALETTE | null {
    if (equipped?.medal) {
        if (equipped.medal === 'none') return null;
        return equipped.medal;
    }
    return DEFAULT_MEDAL_POSES.has(pose) ? 'pertama' : null;
}

function Aura() {
    return (
        <g
            style={{ animation: 'temari-aura-pulse 2.4s ease-in-out infinite', transformOrigin: '60px 55px' }}
        >
            <defs>
                <radialGradient id="temari-aura-grad">
                    <stop offset="0%" stopColor="#F5D365" stopOpacity="0.7" />
                    <stop offset="60%" stopColor="#D9B23A" stopOpacity="0.2" />
                    <stop offset="100%" stopColor="#D9B23A" stopOpacity="0" />
                </radialGradient>
            </defs>
            <circle cx="60" cy="55" r="55" fill="url(#temari-aura-grad)" opacity="0.7" />
        </g>
    );
}

function Ears({ tilt }: Readonly<{ tilt: [number, number] }>) {
    return (
        <>
            <g transform={`translate(35, 18) rotate(${tilt[0]})`}>
                <ellipse cx="0" cy="0" rx="9" ry="22" fill={FUR} stroke={FUR_SHADE} strokeWidth="1.2" />
                <ellipse cx="0" cy="2" rx="4" ry="14" fill={INNER_EAR} opacity="0.55" />
            </g>
            <g transform={`translate(85, 18) rotate(${tilt[1]})`}>
                <ellipse cx="0" cy="0" rx="9" ry="22" fill={FUR} stroke={FUR_SHADE} strokeWidth="1.2" />
                <ellipse cx="0" cy="2" rx="4" ry="14" fill={INNER_EAR} opacity="0.55" />
            </g>
        </>
    );
}

function Headband({ band, legendary }: Readonly<{ band: string; legendary: boolean }>) {
    return (
        <>
            <rect x="26" y="46" width="68" height="9" rx="2" fill={band} />
            <rect x="60" y="42" width="8" height="6" rx="1" fill={band} transform="rotate(28 64 45)" />
            <rect x="64" y="44" width="6" height="14" rx="1" fill={band} transform="rotate(28 67 51)" />
            {legendary && (
                <path
                    d="M 60 49 l 1 -3 l 1 3 l 3 1 l -3 1 l -1 3 l -1 -3 l -3 -1 z"
                    fill="#fff"
                    opacity="0.95"
                />
            )}
        </>
    );
}

function Cheeks() {
    return (
        <>
            <ellipse cx="42" cy="72" rx="5" ry="3" fill={CHEEK} opacity="0.6" />
            <ellipse cx="78" cy="72" rx="5" ry="3" fill={CHEEK} opacity="0.6" />
        </>
    );
}

function Eyes({ shape }: Readonly<{ shape: EyeShape }>) {
    if (shape === 'big') {
        return (
            <>
                <circle cx="48" cy="65" r="4" fill={EYE} />
                <circle cx="72" cy="65" r="4" fill={EYE} />
                <circle cx="49.5" cy="63.5" r="1.5" fill="#fff" />
                <circle cx="73.5" cy="63.5" r="1.5" fill="#fff" />
            </>
        );
    }
    if (shape === 'side') {
        return (
            <>
                <circle cx="50" cy="65" r="3" fill={EYE} />
                <circle cx="74" cy="65" r="3" fill={EYE} />
            </>
        );
    }
    if (shape === 'sad') {
        return (
            <>
                <path d="M 45 64 Q 48 68 51 64" stroke={EYE} strokeWidth="2" fill="none" strokeLinecap="round" />
                <path d="M 69 64 Q 72 68 75 64" stroke={EYE} strokeWidth="2" fill="none" strokeLinecap="round" />
            </>
        );
    }
    return (
        <>
            <circle cx="48" cy="65" r="3" fill={EYE} />
            <circle cx="72" cy="65" r="3" fill={EYE} />
            <circle cx="49" cy="64" r="1" fill="#fff" />
            <circle cx="73" cy="64" r="1" fill="#fff" />
        </>
    );
}

function Mouth({ shape }: Readonly<{ shape: MouthShape }>) {
    if (shape === 'open') {
        return <ellipse cx="60" cy="83" rx="5" ry="4" fill={EYE} />;
    }
    if (shape === 'small') {
        return <path d="M 56 81 Q 60 83 64 81" stroke={EYE} strokeWidth="1.4" fill="none" strokeLinecap="round" />;
    }
    if (shape === 'frown') {
        return <path d="M 53 84 Q 60 78 67 84" stroke={EYE} strokeWidth="1.6" fill="none" strokeLinecap="round" />;
    }
    return <path d="M 53 80 Q 60 86 67 80" stroke={EYE} strokeWidth="1.6" fill="none" strokeLinecap="round" />;
}

function Pita() {
    return (
        <g>
            <path d="M 32 94 L 88 110 L 86 116 L 30 100 Z" fill="#6B8E6F" />
            <path d="M 32 94 L 88 110" stroke="#5C7C60" strokeWidth="0.6" />
            <circle cx="88" cy="113" r="3" fill="#5C7C60" />
        </g>
    );
}

function Medal({
    coin,
    glow,
    ringGlow,
}: Readonly<{ coin: string; glow: string; ringGlow: boolean }>) {
    return (
        <g transform="translate(60, 100)">
            <path d="M -10 -8 L -3 4 L 0 -2 Z" fill="#6B8E6F" />
            <path d="M 10 -8 L 3 4 L 0 -2 Z" fill="#6B8E6F" opacity="0.85" />
            <circle cx="0" cy="8" r="9" fill={coin} stroke={FUR_SHADE} strokeWidth="1" />
            <circle cx="0" cy="8" r="6" fill="none" stroke="#fff" strokeWidth="0.8" opacity="0.5" />
            <text
                x="0"
                y="11"
                fontSize="7"
                textAnchor="middle"
                fill="#fff"
                fontFamily="serif"
                fontStyle="italic"
                fontWeight="700"
            >
                T
            </text>
            {ringGlow && (
                <circle cx="0" cy="8" r="13" fill="none" stroke={glow} strokeWidth="1.5" opacity="0.6" />
            )}
        </g>
    );
}

function Sparkles({ innerEarHex }: Readonly<{ innerEarHex: string }>) {
    return (
        <g style={{ animation: 'temari-spin-sparkle 6s linear infinite', transformOrigin: '60px 60px' }}>
            <path
                d="M 12 30 l 2 -6 l 2 6 l 6 2 l -6 2 l -2 6 l -2 -6 l -6 -2 z"
                fill="#D9B23A"
                opacity="0.85"
            />
            <path
                d="M 102 38 l 1.5 -4 l 1.5 4 l 4 1.5 l -4 1.5 l -1.5 4 l -1.5 -4 l -4 -1.5 z"
                fill={innerEarHex}
                opacity="0.9"
            />
            <path
                d="M 100 100 l 1 -3 l 1 3 l 3 1 l -3 1 l -1 3 l -1 -3 l -3 -1 z"
                fill="#D9B23A"
                opacity="0.8"
            />
        </g>
    );
}
