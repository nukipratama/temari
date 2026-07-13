import { memo, useId, type CSSProperties } from 'react';

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
    medal?: 'pertama' | 'emas' | 'perak' | 'platina' | 'none';
    kaus?: 'pemula' | 'pagi' | 'hujan' | 'legendaris' | null;
    celana?: 'ringan' | 'jarak' | 'split' | 'maraton' | null;
    sepatu?: 'basic' | 'cepat' | 'tahan' | 'legendaris' | null;
    aura?: 'pemanasan' | 'gerah' | 'tenang' | 'jagoan' | 'angin' | true | null;
}

export interface TemariProtoProps {
    pose?: TemariPose;
    size?: number;
    /** Cream-bg vs sky-bg surface — currently only swaps the cheek tone. */
    tone?: 'cream' | 'sky';
    equipped?: TemariEquipped | null;
    /** `true` = pose-driven animation, `false` = static, string = explicit CSS animation. */
    animate?: boolean | string;
    /**
     * Soft SVG drop shadow under the character. Default `true`. Set `false` when
     * the mascot sits inside a 3D-transformed ancestor (e.g. the `.kartu-tilt`
     * card corner) — Chromium won't rasterise an SVG filter under `perspective()`,
     * which makes the whole filtered group vanish.
     */
    dropShadow?: boolean;
    className?: string;
}

// ── Palette constants ───────────────────────────────────────────────

const FUR = '#F2DAB6';
const FUR_SHADE = '#DCC097';
const FUR_DARK = '#BE9759';
const INNER_EAR = '#E8A076';
const EYE = '#1A1812';
const CHEEK = '#E89B8E';
const OUTLINE = '#3b2f1f';
const DEFAULT_SHIRT = '#0e7a4c';

// ── Headband palette ────────────────────────────────────────────────

interface HeadbandPalette {
    band: string;
    accent: string;
}

const HEADBAND_PALETTE: Record<string, HeadbandPalette> = {
    ember: { band: '#C4623F', accent: '#A8512C' },
    epik: { band: '#7B5BB6', accent: '#5E4490' },
    legendaris: { band: '#D9B23A', accent: '#B8941E' },
};

// ── Medal palette ───────────────────────────────────────────────────

const MEDAL_PALETTE: Record<string, { coin: string; glow: string; ring: boolean }> = {
    pertama: { coin: '#C77F4A', glow: '#E0A06E', ring: false },
    emas: { coin: '#D9B23A', glow: '#F5D365', ring: false },
    perak: { coin: '#A8B4C0', glow: '#C8D4E0', ring: true },
    platina: { coin: '#B8D4E8', glow: '#D8F0FF', ring: true },
};

// ── Kaus (shirt/jersey) palette ─────────────────────────────────────

const KAUS_PALETTE: Record<string, { fill: string; trim: string; emblem: string }> = {
    pemula: { fill: '#E8E4DC', trim: '#CCC8C0', emblem: '#A09888' },
    pagi: { fill: '#F5D365', trim: '#D9B23A', emblem: '#B8941E' },
    hujan: { fill: '#5E89B5', trim: '#4A6F94', emblem: '#8CB4D8' },
    legendaris: { fill: '#D9B23A', trim: '#B8941E', emblem: '#F5D365' },
};

// ── Celana (pants/shorts) palette ───────────────────────────────────

const CELANA_PALETTE: Record<string, { fill: string; stripe: string }> = {
    ringan: { fill: '#3d362a', stripe: '#6e6452' },
    jarak: { fill: '#07492d', stripe: '#d9764a' },
    split: { fill: '#2c355c', stripe: '#e8a076' },
    maraton: { fill: '#1a1812', stripe: '#d9b23a' },
};

// ── Sepatu (shoes) palette ──────────────────────────────────────────

const SEPATU_PALETTE: Record<string, { upper: string; sole: string; accent: string }> = {
    basic: { upper: '#A09888', sole: '#ffffff', accent: '#6e6452' },
    cepat: { upper: '#d9764a', sole: '#ffffff', accent: '#b75f37' },
    tahan: { upper: '#3d5a4f', sole: '#ffffff', accent: '#6b8e6f' },
    legendaris: { upper: '#D9B23A', sole: '#ffffff', accent: '#F5D365' },
};

// ── Aura palette ────────────────────────────────────────────────────

const AURA_PALETTE: Record<string, { inner: string; mid: string; outer: string }> = {
    pemanasan: { inner: '#F5D365', mid: '#D9B23A', outer: '#D9B23A' },
    gerah: { inner: '#E8A076', mid: '#C4623F', outer: '#C4623F' },
    tenang: { inner: '#8CB4D8', mid: '#5E89B5', outer: '#5E89B5' },
    jagoan: { inner: '#D8F0FF', mid: '#D9B23A', outer: '#B8941E' },
    angin: { inner: '#D6F0EC', mid: '#6FBAAE', outer: '#5FA79B' },
};

// ── Pose configs ────────────────────────────────────────────────────

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

const SPARKLE_POSES = new Set<TemariPose>(['pumped', 'excited', 'glow']);

// ── Arm swing per pose ──────────────────────────────────────────────

const ARM_ROTATION: Record<TemariPose, [number, number]> = {
    proud: [-15, 15],
    pumped: [-30, 30],
    excited: [-40, 40],
    holding: [-10, 10],
    reading: [-5, 20],
    wobble: [20, -20],
    observational: [-12, 12],
    glow: [-25, 25],
};

// Poses where Temari grips a book in both fists. The forearms bend inward to a
// fixed grip (ARM_ROTATION is ignored) so the fists stay locked on the book.
const HELD_POSES = new Set<TemariPose>(['holding', 'reading']);

// ── Helpers ─────────────────────────────────────────────────────────

function resolveAuraKey(equipped: TemariEquipped | null): string | null {
    if (!equipped?.aura) return null;
    if (typeof equipped.aura === 'string') return equipped.aura;
    return 'pemanasan';
}

// ── Main component ──────────────────────────────────────────────────

function TemariProto({
    pose = 'proud',
    size = 140,
    tone = 'cream',
    equipped = null,
    animate = false,
    dropShadow = true,
    className,
}: Readonly<TemariProtoProps>) {
    // Full-body viewBox: head at y~0..56, body at y~56..96, legs at y~96..130.
    // y starts at -24 so ears (tip y≈-20 on excited pose) are never clipped when
    // the SVG is rasterized to canvas for the share card. ViewBox: x[0..120], y[-24..134] = 158 tall.
    const viewW = 120;
    const viewH = 158;
    const aspectHeight = (size * viewH) / viewW;

    const headbandKey = equipped?.headband ?? null;
    const hb = HEADBAND_PALETTE[headbandKey ?? 'ember'] ?? HEADBAND_PALETTE.ember;

    const medalKey = (!equipped?.medal || equipped.medal === 'none') ? null : equipped.medal;
    const medal = medalKey ? (MEDAL_PALETTE[medalKey] ?? MEDAL_PALETTE.pertama) : null;

    const auraKey = resolveAuraKey(equipped);
    const showAura = auraKey !== null;
    const auraColors = auraKey ? (AURA_PALETTE[auraKey] ?? AURA_PALETTE.pemanasan) : null;

    const showSparkle = SPARKLE_POSES.has(pose) || showAura;
    const kausKey = equipped?.kaus ?? null;
    const kausColors = kausKey ? (KAUS_PALETTE[kausKey] ?? KAUS_PALETTE.pemula) : null;

    const celanaKey = equipped?.celana ?? null;
    const celanaColors = celanaKey ? (CELANA_PALETTE[celanaKey] ?? CELANA_PALETTE.ringan) : null;

    const sepatuKey = equipped?.sepatu ?? null;
    const sepatuColors = sepatuKey ? (SEPATU_PALETTE[sepatuKey] ?? SEPATU_PALETTE.basic) : null;

    const earTilt = EAR_TILT[pose];
    const eyeShape = EYE_BY_POSE[pose];
    const mouthShape = MOUTH_BY_POSE[pose];
    const armRot = ARM_ROTATION[pose];
    const held = HELD_POSES.has(pose);

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
                viewBox={`0 -24 ${viewW} ${viewH}`}
                width={size}
                height={aspectHeight}
                style={{ display: 'block', overflow: 'visible' }}
                aria-hidden
            >
                <defs>
                    {/* Drop shadow for lift */}
                    <filter id="temari-shadow" x="-20%" y="-10%" width="140%" height="140%">
                        <feDropShadow dx="0" dy="3" stdDeviation="3" floodColor="#3b2f1f" floodOpacity="0.15" />
                    </filter>
                    {/* Fur radial gradient — lit top-left, deep shade bottom-right */}
                    <radialGradient id="fur-head-grad" cx="42%" cy="34%" r="62%">
                        <stop offset="0%" stopColor="#FFF3DD" />
                        <stop offset="55%" stopColor={FUR} />
                        <stop offset="82%" stopColor={FUR_SHADE} />
                        <stop offset="100%" stopColor={FUR_DARK} />
                    </radialGradient>
                    <radialGradient id="fur-body-grad" cx="42%" cy="28%" r="72%">
                        <stop offset="0%" stopColor="#FFF3DD" />
                        <stop offset="50%" stopColor={FUR} />
                        <stop offset="82%" stopColor={FUR_SHADE} />
                        <stop offset="100%" stopColor={FUR_DARK} />
                    </radialGradient>
                    {/* Garment form-shading overlay — top highlight → bottom shade, works on any fill */}
                    <linearGradient id="garment-shade" x1="18%" y1="0%" x2="62%" y2="100%">
                        <stop offset="0%" stopColor="#fff" stopOpacity="0.28" />
                        <stop offset="34%" stopColor="#fff" stopOpacity="0" />
                        <stop offset="64%" stopColor="#000" stopOpacity="0" />
                        <stop offset="100%" stopColor="#000" stopOpacity="0.34" />
                    </linearGradient>
                    {/* Metallic coin sheen — diagonal highlight → shade */}
                    <linearGradient id="coin-sheen" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" stopColor="#fff" stopOpacity="0.6" />
                        <stop offset="44%" stopColor="#fff" stopOpacity="0" />
                        <stop offset="100%" stopColor="#000" stopOpacity="0.3" />
                    </linearGradient>
                    {/* Forehead highlight */}
                    <radialGradient id="fur-highlight" cx="50%" cy="30%" r="40%">
                        <stop offset="0%" stopColor="#fff" stopOpacity="0.35" />
                        <stop offset="100%" stopColor="#fff" stopOpacity="0" />
                    </radialGradient>
                    {/* Cheek blush gradient */}
                    <radialGradient id="cheek-blush-l" cx="50%" cy="50%" r="50%">
                        <stop offset="0%" stopColor={CHEEK} stopOpacity="0.7" />
                        <stop offset="100%" stopColor={CHEEK} stopOpacity="0" />
                    </radialGradient>
                    <radialGradient id="cheek-blush-r" cx="50%" cy="50%" r="50%">
                        <stop offset="0%" stopColor={CHEEK} stopOpacity="0.7" />
                        <stop offset="100%" stopColor={CHEEK} stopOpacity="0" />
                    </radialGradient>
                    {/* Inner ear shadow */}
                    <radialGradient id="ear-inner-grad" cx="50%" cy="40%" r="55%">
                        <stop offset="0%" stopColor={INNER_EAR} stopOpacity="0.6" />
                        <stop offset="100%" stopColor="#B8785A" stopOpacity="0.4" />
                    </radialGradient>
                    {/* Arm limb — bright lit top edge → deep shaded underside for a rounded 3D tube */}
                    <linearGradient id="fur-arm-grad" x1="20%" y1="0%" x2="80%" y2="100%">
                        <stop offset="0%" stopColor="#FFF3DD" />
                        <stop offset="42%" stopColor={FUR} />
                        <stop offset="78%" stopColor="#D2B485" />
                        <stop offset="100%" stopColor={FUR_DARK} />
                    </linearGradient>
                    {/* Fist/hand — strong highlight top-left → dark bottom-right for volume */}
                    <radialGradient id="fur-fist-grad" cx="36%" cy="30%" r="75%">
                        <stop offset="0%" stopColor="#FFF6E6" />
                        <stop offset="50%" stopColor={FUR} />
                        <stop offset="100%" stopColor={FUR_DARK} />
                    </radialGradient>
                </defs>

                {/* Aura (behind everything) */}
                {showAura && auraColors && <AuraLayer colors={auraColors} />}

                {/* Ground shadow */}
                <ellipse cx="60" cy="130" rx="24" ry="3.5" fill={EYE} opacity="0.1" />

                {/* Character group with drop shadow (skipped inside 3D-tilt cards) */}
                <g filter={dropShadow ? 'url(#temari-shadow)' : undefined}>
                    {/* Legs */}
                    <Legs sepatuColors={sepatuColors} />

                    {/* Body (torso) */}
                    <Body
                        kausColors={kausColors}
                        celanaColors={celanaColors}
                        emblemColor={headbandKey ? hb.band : FUR_SHADE}
                    />

                    {/* Arms */}
                    <Arms armRot={armRot} held={held} kausColors={kausColors} wristColor={headbandKey ? hb.band : FUR_SHADE} />

                    {/* Soft shadow the head casts onto the chest */}
                    <ellipse cx="60" cy="57" rx="17" ry="5" fill={OUTLINE} opacity="0.13" />

                    {/* Head */}
                    <Head
                        earTilt={earTilt}
                        hb={hb}
                        headbandKey={headbandKey}
                        eyeShape={eyeShape}
                        mouthShape={mouthShape}
                    />

                    {/* Medal (on chest) */}
                    {medal && <MedalLayer medal={medal} />}

                    {/* Book gripped in both paws — drawn last so it sits in front of the
                        chest/medal and the paws read as holding it up to read */}
                    {held && <HeldObject />}
                </g>

                {/* Sparkles */}
                {showSparkle && <Sparkles innerEarHex={INNER_EAR} />}
            </svg>
        </div>
    );
}

// ── Aura ─────────────────────────────────────────────────────────────

function AuraLayer({ colors }: Readonly<{ colors: { inner: string; mid: string; outer: string } }>) {
    // The gradient id must be unique per instance: it was document-global, so with
    // several TemariProto auras on one page (e.g. the accessory grid) every circle
    // resolved to the first-mounted aura's colors, misrepresenting the rest.
    const gradId = `temari-aura-grad-${useId()}`;
    return (
        <g
            style={{ animation: 'temari-aura-pulse 2.4s ease-in-out infinite', transformOrigin: '60px 60px' }}
        >
            <defs>
                <radialGradient id={gradId}>
                    <stop offset="0%" stopColor={colors.inner} stopOpacity="0.7" />
                    <stop offset="60%" stopColor={colors.mid} stopOpacity="0.2" />
                    <stop offset="100%" stopColor={colors.outer} stopOpacity="0" />
                </radialGradient>
            </defs>
            <circle cx="60" cy="60" r="68" fill={`url(#${gradId})`} opacity="0.7" />
        </g>
    );
}

// ── Head (with ears, headband, eyes, mouth) ──────────────────────────

function Head({
    earTilt,
    hb,
    headbandKey,
    eyeShape,
    mouthShape,
}: Readonly<{
    earTilt: [number, number];
    hb: HeadbandPalette;
    headbandKey: string | null;
    eyeShape: EyeShape;
    mouthShape: MouthShape;
}>) {
    return (
        <g>
            {/* Ears */}
            <Ears tilt={earTilt} />
            {/* Head circle — gradient fill for 3D */}
            <ellipse cx="60" cy="28" rx="34" ry="30" fill="url(#fur-head-grad)" stroke={FUR_SHADE} strokeWidth="1.2" />
            {/* Forehead highlight */}
            <ellipse cx="60" cy="20" rx="22" ry="14" fill="url(#fur-highlight)" />
            {/* Headband — only when equipped */}
            {headbandKey && <Headband band={hb.band} legendary={headbandKey === 'legendaris'} />}
            {/* Cheeks — softer blush */}
            <Cheeks />
            {/* Eyes */}
            <Eyes shape={eyeShape} />
            {/* Nose dot */}
            <ellipse cx="60" cy="42" rx="2.5" ry="2" fill={headbandKey ? hb.band : FUR_SHADE} />
            {/* Mouth */}
            <Mouth shape={mouthShape} />
        </g>
    );
}

// ── Body (torso) ─────────────────────────────────────────────────────

function Body({
    kausColors,
    celanaColors,
    emblemColor,
}: Readonly<{
    kausColors: { fill: string; trim: string; emblem: string } | null;
    celanaColors: { fill: string; stripe: string } | null;
    emblemColor: string;
}>) {
    return (
        <g>
            {/* Torso shape — gradient fill for 3D */}
            <path
                d="M 36 56 Q 32 70 34 86 Q 36 92 44 92 L 76 92 Q 84 92 86 86 Q 88 70 84 56 Z"
                fill="url(#fur-body-grad)"
                stroke={FUR_SHADE}
                strokeWidth="1.2"
                strokeLinejoin="round"
            />

            {/* Celana (pants/shorts) — lower torso */}
            {celanaColors ? (
                <g>
                    <path
                        d="M 34 78 Q 33 84 34 86 Q 36 92 44 92 L 76 92 Q 84 92 86 86 Q 87 84 86 78 Z"
                        fill={celanaColors.fill}
                        stroke={OUTLINE}
                        strokeWidth={0.8}
                        strokeLinejoin="round"
                    />
                    {/* Side stripes */}
                    <rect x="36" y="80" width="1.4" height="10" fill={celanaColors.stripe} />
                    <rect x="83" y="80" width="1.4" height="10" fill={celanaColors.stripe} />
                </g>
            ) : (
                <path
                    d="M 34 78 Q 33 84 34 86 Q 36 92 44 92 L 76 92 Q 84 92 86 86 Q 87 84 86 78 Z"
                    fill="#07492d"
                    stroke={OUTLINE}
                    strokeWidth={0.8}
                    strokeLinejoin="round"
                />
            )}
            {/* Shorts form-shading — top light → bottom shade */}
            <path d="M 34 78 Q 33 84 34 86 Q 36 92 44 92 L 76 92 Q 84 92 86 86 Q 87 84 86 78 Z" fill="url(#garment-shade)" />

            {/* Kaus (shirt/jersey) — upper torso */}
            {kausColors ? (
                <g>
                    <path
                        d="M 36 55 Q 35 64 34 78 L 86 78 Q 85 64 84 55 Q 76 58 60 58 Q 44 58 36 55 Z"
                        fill={kausColors.fill}
                        stroke={OUTLINE}
                        strokeWidth={0.8}
                        strokeLinejoin="round"
                    />
                    {/* Collar trim */}
                    <path
                        d="M 44 55 Q 52 60 60 58 Q 68 60 76 55"
                        stroke={kausColors.trim}
                        strokeWidth="1.4"
                        fill="none"
                        strokeLinecap="round"
                    />
                    {/* Chest emblem — small "T" */}
                    <circle cx="60" cy="66" r="3.2" fill={emblemColor} stroke={OUTLINE} strokeWidth="0.6" />
                    <text
                        x="60"
                        y="67.5"
                        textAnchor="middle"
                        fontSize="3.2"
                        fontWeight="bold"
                        fill="#ffffff"
                        fontFamily="sans-serif"
                    >
                        T
                    </text>
                </g>
            ) : (
                <g>
                    <path
                        d="M 36 55 Q 35 64 34 78 L 86 78 Q 85 64 84 55 Q 76 58 60 58 Q 44 58 36 55 Z"
                        fill={DEFAULT_SHIRT}
                        stroke={OUTLINE}
                        strokeWidth={0.8}
                        strokeLinejoin="round"
                    />
                    {/* Collar trim */}
                    <path
                        d="M 44 55 Q 52 60 60 58 Q 68 60 76 55"
                        stroke="#094d30"
                        strokeWidth="1.4"
                        fill="none"
                        strokeLinecap="round"
                    />
                    {/* Default chest emblem */}
                    <circle cx="60" cy="66" r="3.2" fill={emblemColor} stroke={OUTLINE} strokeWidth="0.6" />
                    <text
                        x="60"
                        y="67.5"
                        textAnchor="middle"
                        fontSize="3.2"
                        fontWeight="bold"
                        fill="#ffffff"
                        fontFamily="sans-serif"
                    >
                        T
                    </text>
                </g>
            )}
            {/* Shirt form-shading — top light → bottom shade */}
            <path d="M 36 55 Q 35 64 34 78 L 86 78 Q 85 64 84 55 Q 76 58 60 58 Q 44 58 36 55 Z" fill="url(#garment-shade)" />
        </g>
    );
}

// ── Arms ──────────────────────────────────────────────────────────────

function Arms({
    armRot,
    held,
    kausColors,
    wristColor,
}: Readonly<{
    armRot: [number, number];
    held: boolean;
    kausColors: { fill: string; trim: string; emblem: string } | null;
    wristColor: string;
}>) {
    const sleeveColor = kausColors ? kausColors.fill : DEFAULT_SHIRT;
    // Held poses keep the arms unrotated — the bent-inward grip geometry already
    // brings both hands to the book at centre. Otherwise the arms hang at the
    // sides and swing slightly OUTWARD with energy (negate ARM_ROTATION, which
    // was tuned for the old raised arms, so the hands stay clear of the body).
    const [rotL, rotR] = held ? [0, 0] : [-armRot[0], -armRot[1]];
    return (
        <>
            <Arm side="left" rot={rotL} held={held} sleeveColor={sleeveColor} wristColor={wristColor} />
            <Arm side="right" rot={rotR} held={held} sleeveColor={sleeveColor} wristColor={wristColor} />
        </>
    );
}

// Arm geometry, built per side in one un-mirrored frame so the light stays
// consistently top-left across both arms (mirroring via scale would flip the
// shading and kill the 3D read). `s` = inward sign (+1 left, -1 right); a runner's
// bent elbow — upper arm down the side, forearm angled forward to a relaxed fist.
// `held` swings the forearm further in so both fists meet on a book.
function armGeo(s: number, held: boolean): {
    d: string;
    sleeve: string;
    elbow: readonly [number, number] | null;
    wrist: readonly [number, number];
    fist: readonly [number, number];
} {
    if (held) {
        // Forearm bends up and inward so both hands grip a book at centre.
        return {
            d: `M 0 1 Q ${1 * s} 6 ${1.6 * s} 9 Q ${6 * s} 11.8 ${11.6 * s} 13`,
            sleeve: `M 0 1 Q ${1 * s} 5 ${1.5 * s} 8`,
            elbow: [1.6 * s, 9],
            wrist: [9.4 * s, 12.2],
            fist: [11.6 * s, 13.4],
        };
    }
    // Relaxed: arm hangs down the side, bowing slightly outward, hand low at the hip.
    return {
        d: `M 0 1 Q ${-2 * s} 12 ${-1.4 * s} 21 Q ${-1.1 * s} 24 ${-0.7 * s} 25`,
        sleeve: `M 0 1 Q ${-1.5 * s} 7 ${-1.5 * s} 11`,
        elbow: null,
        wrist: [-0.7 * s, 23.8],
        fist: [-0.7 * s, 27.6],
    };
}

function Arm({
    side,
    rot,
    held,
    sleeveColor,
    wristColor,
}: Readonly<{ side: 'left' | 'right'; rot: number; held: boolean; sleeveColor: string; wristColor: string }>) {
    // Shoulder pivots sit at the torso's upper edge (x≈36/84, y56) so the arm
    // overlaps the body with no gap; the whole arm swings around that pivot.
    const pivotX = side === 'left' ? 36 : 84;
    const s = side === 'left' ? 1 : -1; // +x is inward for the left arm
    const geo = armGeo(s, held);
    return (
        <g transform={`translate(${pivotX}, 58) rotate(${rot})`}>
            {/* Contact shadow where the arm tucks under the shoulder */}
            <ellipse cx="0" cy="2.5" rx="4.6" ry="3" fill={OUTLINE} opacity="0.1" />
            {/* Limb — outline → gradient fill → underside shadow → top rim-light = rounded 3D tube */}
            <path d={geo.d} fill="none" stroke={FUR_DARK} strokeWidth="8" strokeLinecap="round" strokeLinejoin="round" />
            <path d={geo.d} fill="none" stroke="url(#fur-arm-grad)" strokeWidth="6.6" strokeLinecap="round" strokeLinejoin="round" />
            <path d={geo.d} fill="none" stroke={FUR_DARK} strokeWidth="3" strokeLinecap="round" opacity="0.3" transform="translate(0.7, 1.3)" />
            {/* Elbow ambient occlusion (bent poses only) */}
            {geo.elbow && <ellipse cx={geo.elbow[0]} cy={geo.elbow[1]} rx="2.4" ry="2" fill={FUR_DARK} opacity="0.18" />}
            <path d={geo.d} fill="none" stroke="#FFF6E6" strokeWidth="1.8" strokeLinecap="round" opacity="0.55" transform="translate(-0.7, -1.4)" />
            {/* Sleeve over the shoulder */}
            <path d={geo.sleeve} fill="none" stroke={sleeveColor} strokeWidth="7.4" strokeLinecap="round" />
            <path d={geo.sleeve} fill="none" stroke="#000" strokeWidth="2" strokeLinecap="round" opacity="0.12" transform="translate(0.6, 1.1)" />
            {/* Fist */}
            <Fist cx={geo.fist[0]} cy={geo.fist[1]} thumb={s} wrist={geo.wrist} wristColor={wristColor} />
        </g>
    );
}

function Fist({
    cx,
    cy,
    thumb,
    wrist,
    wristColor,
}: Readonly<{ cx: number; cy: number; thumb: number; wrist: readonly [number, number]; wristColor: string }>) {
    return (
        <g>
            {/* Cast shadow on the body under the fist */}
            <ellipse cx={cx + 1.2} cy={cy + 3.6} rx="4.4" ry="2.2" fill={OUTLINE} opacity="0.16" />
            {/* Wristband between forearm and fist */}
            <ellipse cx={wrist[0]} cy={wrist[1]} rx="3.4" ry="2.2" fill={wristColor} stroke={OUTLINE} strokeWidth="0.3" />
            {/* Fist — gradient sphere with an outline */}
            <circle cx={cx} cy={cy} r="4.8" fill={FUR_DARK} />
            <circle cx={cx} cy={cy} r="4.4" fill="url(#fur-fist-grad)" />
            {/* Thumb wrapped over the front (inward side) */}
            <ellipse
                cx={cx + 2.6 * thumb}
                cy={cy + 1.2}
                rx="1.8"
                ry="2.3"
                fill="url(#fur-fist-grad)"
                stroke={FUR_DARK}
                strokeWidth="0.5"
                transform={`rotate(${20 * thumb} ${cx + 2.6 * thumb} ${cy + 1.2})`}
            />
            {/* Knuckle creases */}
            <path
                d={`M ${cx - 1.6 * thumb} ${cy - 1.3} L ${cx - 1.6 * thumb} ${cy + 1.3} M ${cx + 0.6 * thumb} ${cy - 1.6} L ${cx + 0.6 * thumb} ${cy + 1}`}
                stroke={FUR_DARK}
                strokeWidth="0.5"
                strokeLinecap="round"
                opacity="0.6"
            />
            {/* Specular highlight — world top-left */}
            <circle cx={cx - 1.6} cy={cy - 1.8} r="1.7" fill="#FFF6E6" opacity="0.65" />
        </g>
    );
}

// ── Held object (book) ─────────────────────────────────────────────────

function HeldObject() {
    return (
        <g>
            <defs>
                <radialGradient id="temari-book-glow">
                    <stop offset="0%" stopColor="#F5D365" stopOpacity="0.55" />
                    <stop offset="100%" stopColor="#F5D365" stopOpacity="0" />
                </radialGradient>
            </defs>
            {/* Warm glow behind the book */}
            <ellipse
                cx="60"
                cy="76"
                rx="20"
                ry="14"
                fill="url(#temari-book-glow)"
                style={{ animation: 'temari-breathe 3s ease-in-out infinite', transformOrigin: '60px 76px' }}
            />
            {/* Open book — two pages meeting at the spine */}
            <path
                d="M 60 71 Q 51 68.5 48 70 L 48 81 Q 51 79.5 60 82 Z"
                fill="#FAF3E6"
                stroke={OUTLINE}
                strokeWidth="0.6"
                strokeLinejoin="round"
            />
            <path
                d="M 60 71 Q 69 68.5 72 70 L 72 81 Q 69 79.5 60 82 Z"
                fill="#FBF6EC"
                stroke={OUTLINE}
                strokeWidth="0.6"
                strokeLinejoin="round"
            />
            {/* Spine */}
            <path d="M 60 71 L 60 82" stroke={OUTLINE} strokeWidth="0.7" strokeLinecap="round" />
            {/* Text lines */}
            <path
                d="M 52 73.6 L 57.5 74.3 M 52 76.1 L 57.5 76.7 M 52 78.6 L 57.5 79.1"
                stroke="#C9BCA3"
                strokeWidth="0.5"
                strokeLinecap="round"
            />
            <path
                d="M 62.5 74.3 L 68 73.6 M 62.5 76.7 L 68 76.1 M 62.5 79.1 L 68 78.6"
                stroke="#C9BCA3"
                strokeWidth="0.5"
                strokeLinecap="round"
            />
        </g>
    );
}

// ── Legs ──────────────────────────────────────────────────────────────

function Legs({ sepatuColors }: Readonly<{ sepatuColors: { upper: string; sole: string; accent: string } | null }>) {
    const shoe = sepatuColors ?? { upper: '#A09888', sole: '#ffffff', accent: '#6e6452' };
    return (
        <g>
            {/* Left leg */}
            <g transform="translate(48, 92)">
                {/* Thigh — tapers from hip to knee with slight forward lean */}
                <path d="M -4 0 Q -4.5 7 -3.5 14 L 3.5 14 Q 4.5 7 4 0 Z" fill={FUR} stroke={FUR_SHADE} strokeWidth="0.8" />
                {/* Shoe */}
                <g stroke={OUTLINE} strokeWidth="0.8" strokeLinejoin="round">
                    <path d="M -8 12 L 6 12 Q 8 15 6 18 Q 6 21 4 21 L -6 21 Q -9 21 -9 18 Q -10 14 -8 12 Z" fill={shoe.upper} />
                    <path d="M -8 12 L 6 12 L 6 15 L -8 15 Z" fill={shoe.accent} opacity="0.4" stroke="none" />
                    <rect x="-9" y="18" width="16" height="3" rx="1.2" fill={shoe.sole} />
                </g>
            </g>
            {/* Right leg */}
            <g transform="translate(72, 92)">
                {/* Thigh — tapers from hip to knee */}
                <path d="M -4 0 Q -4.5 7 -3.5 14 L 3.5 14 Q 4.5 7 4 0 Z" fill={FUR} stroke={FUR_SHADE} strokeWidth="0.8" />
                {/* Shoe */}
                <g stroke={OUTLINE} strokeWidth="0.8" strokeLinejoin="round">
                    <path d="M -6 12 L 8 12 Q 10 14 9 18 Q 9 21 6 21 L -4 21 Q -7 21 -7 18 Q -8 14 -6 12 Z" fill={shoe.upper} />
                    <path d="M -6 12 L 8 12 L 8 15 L -6 15 Z" fill={shoe.accent} opacity="0.4" stroke="none" />
                    <rect x="-7" y="18" width="16" height="3" rx="1.2" fill={shoe.sole} />
                </g>
            </g>
        </g>
    );
}

// ── Medal ─────────────────────────────────────────────────────────────

function MedalLayer({
    medal,
}: Readonly<{ medal: { coin: string; glow: string; ring: boolean } }>) {
    return (
        <g transform="translate(60, 70)">
            {/* Ribbon — V lanyard from the collar, two tones for depth */}
            <path d="M -5 -13 L -1.1 -1 L 1.1 -1 L -2.3 -13 Z" fill="#A8512C" />
            <path d="M 5 -13 L 1.1 -1 L -1.1 -1 L 2.3 -13 Z" fill="#C4623F" />
            {/* Coin — metallic disc with bezel */}
            <circle cx="0" cy="6.5" r="6.6" fill={medal.coin} stroke={OUTLINE} strokeWidth="0.5" />
            <circle cx="0" cy="6.5" r="6.6" fill="none" stroke="#fff" strokeWidth="0.7" opacity="0.35" />
            <circle cx="0" cy="6.5" r="5" fill="none" stroke={OUTLINE} strokeWidth="0.4" opacity="0.22" />
            {/* Embossed star */}
            <path
                d="M 0 3.1 L 0.88 5.29 L 3.23 5.45 L 1.43 6.96 L 2 9.25 L 0 8 L -2 9.25 L -1.43 6.96 L -3.23 5.45 L -0.88 5.29 Z"
                fill="#FFF8E8"
                opacity="0.9"
            />
            {/* Metallic sheen + shade */}
            <circle cx="0" cy="6.5" r="6.6" fill="url(#coin-sheen)" />
            {/* Specular glint */}
            <ellipse cx="-2.4" cy="3.8" rx="1.8" ry="1.1" fill="#fff" opacity="0.55" transform="rotate(-35 -2.4 3.8)" />
            {/* Platina/perak glow ring */}
            {medal.ring && (
                <circle cx="0" cy="6.5" r="9.5" fill="none" stroke={medal.glow} strokeWidth="1.3" opacity="0.55" />
            )}
        </g>
    );
}

// ── Head sub-components ───────────────────────────────────────────────

function Ears({ tilt }: Readonly<{ tilt: [number, number] }>) {
    return (
        <>
            <g transform={`translate(35, 8) rotate(${tilt[0]})`}>
                <ellipse cx="0" cy="-10" rx="8" ry="18" fill={FUR} stroke={FUR_SHADE} strokeWidth="1.2" />
                <ellipse cx="0" cy="-8" rx="3.5" ry="12" fill="url(#ear-inner-grad)" />
            </g>
            <g transform={`translate(85, 8) rotate(${tilt[1]})`}>
                <ellipse cx="0" cy="-10" rx="8" ry="18" fill={FUR} stroke={FUR_SHADE} strokeWidth="1.2" />
                <ellipse cx="0" cy="-8" rx="3.5" ry="12" fill="url(#ear-inner-grad)" />
            </g>
        </>
    );
}

function Headband({ band, legendary }: Readonly<{ band: string; legendary: boolean }>) {
    return (
        <>
            {/* Band wrapping the forehead edge-to-edge, hugging both sides of the
                head (head: cx=60 cy=28 rx=34 ry=30) so it reads as worn, not floating */}
            <path
                d="M 27 19 Q 60 15 93 19 L 93 27 Q 60 23 27 27 Z"
                fill={band}
                stroke={OUTLINE}
                strokeWidth="0.5"
                strokeOpacity="0.25"
                strokeLinejoin="round"
            />
            {/* Lower-edge shade + top highlight for depth */}
            <path d="M 28 26.6 Q 60 22.8 92 26.6" fill="none" stroke="#000" strokeWidth="1.1" strokeOpacity="0.12" strokeLinecap="round" />
            <path d="M 31 18.4 Q 60 14.8 89 18.4" fill="none" stroke="#fff" strokeWidth="1.1" strokeOpacity="0.3" strokeLinecap="round" />
            {/* Knot on the right side near the ear */}
            <path d="M 90 20 L 97 16 L 95 22 L 100 25 L 92 26 Z" fill={band} stroke={OUTLINE} strokeWidth="0.4" strokeOpacity="0.25" strokeLinejoin="round" />
            {/* Tail dangling down the side */}
            <path d="M 95 23 Q 99 30 97 38" stroke={band} strokeWidth="2.6" fill="none" strokeLinecap="round" />
            {legendary && (
                <path
                    d="M 60 19 l 1 -3 l 1 3 l 3 1 l -3 1 l -1 3 l -1 -3 l -3 -1 z"
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
            <ellipse cx="42" cy="40" rx="7" ry="5" fill="url(#cheek-blush-l)" />
            <ellipse cx="78" cy="40" rx="7" ry="5" fill="url(#cheek-blush-r)" />
        </>
    );
}

function Eyes({ shape }: Readonly<{ shape: EyeShape }>) {
    if (shape === 'big') {
        return (
            <>
                <circle cx="48" cy="33" r="4" fill={EYE} />
                <circle cx="72" cy="33" r="4" fill={EYE} />
                <circle cx="49.5" cy="31.5" r="1.5" fill="#fff" />
                <circle cx="73.5" cy="31.5" r="1.5" fill="#fff" />
            </>
        );
    }
    if (shape === 'side') {
        return (
            <>
                <circle cx="50" cy="33" r="3" fill={EYE} />
                <circle cx="74" cy="33" r="3" fill={EYE} />
            </>
        );
    }
    if (shape === 'sad') {
        return (
            <>
                <path d="M 45 32 Q 48 36 51 32" stroke={EYE} strokeWidth="2" fill="none" strokeLinecap="round" />
                <path d="M 69 32 Q 72 36 75 32" stroke={EYE} strokeWidth="2" fill="none" strokeLinecap="round" />
            </>
        );
    }
    return (
        <>
            <circle cx="48" cy="33" r="3" fill={EYE} />
            <circle cx="72" cy="33" r="3" fill={EYE} />
            <circle cx="49" cy="32" r="1" fill="#fff" />
            <circle cx="73" cy="32" r="1" fill="#fff" />
        </>
    );
}

function Mouth({ shape }: Readonly<{ shape: MouthShape }>) {
    if (shape === 'open') {
        return <ellipse cx="60" cy="51" rx="5" ry="4" fill={EYE} />;
    }
    if (shape === 'small') {
        return <path d="M 56 49 Q 60 51 64 49" stroke={EYE} strokeWidth="1.4" fill="none" strokeLinecap="round" />;
    }
    if (shape === 'frown') {
        return <path d="M 53 52 Q 60 46 67 52" stroke={EYE} strokeWidth="1.6" fill="none" strokeLinecap="round" />;
    }
    return <path d="M 53 48 Q 60 54 67 48" stroke={EYE} strokeWidth="1.6" fill="none" strokeLinecap="round" />;
}

// ── Sparkles ──────────────────────────────────────────────────────────

function Sparkles({ innerEarHex }: Readonly<{ innerEarHex: string }>) {
    return (
        <g style={{ animation: 'temari-spin-sparkle 6s linear infinite', transformOrigin: '60px 60px' }}>
            <path
                d="M 12 10 l 2 -6 l 2 6 l 6 2 l -6 2 l -2 6 l -2 -6 l -6 -2 z"
                fill="#D9B23A"
                opacity="0.85"
            />
            <path
                d="M 102 18 l 1.5 -4 l 1.5 4 l 4 1.5 l -4 1.5 l -1.5 4 l -1.5 -4 l -4 -1.5 z"
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

/**
 * Field-level comparison so a parent that re-renders with a fresh inline
 * `equipped={{...}}` object doesn't force this 900-line SVG tree to rebuild.
 */
function equippedEqual(a: TemariEquipped | null, b: TemariEquipped | null): boolean {
    if (a === b) {
        return true;
    }
    if (a === null || b === null) {
        return false;
    }
    return a.headband === b.headband
        && a.medal === b.medal
        && a.kaus === b.kaus
        && a.celana === b.celana
        && a.sepatu === b.sepatu
        && a.aura === b.aura;
}

function propsEqual(a: Readonly<TemariProtoProps>, b: Readonly<TemariProtoProps>): boolean {
    return a.pose === b.pose
        && a.size === b.size
        && a.tone === b.tone
        && a.animate === b.animate
        && a.dropShadow === b.dropShadow
        && a.className === b.className
        && equippedEqual(a.equipped ?? null, b.equipped ?? null);
}

export default memo(TemariProto, propsEqual);
