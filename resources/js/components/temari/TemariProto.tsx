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

/**
 * Discrete season-arc coverage states for the Plan tab's season summary —
 * the only render site that passes this prop. `deload` is intentionally not
 * a variant here: the caller resolves a deload week to the last non-deload
 * phase before rendering, so accretion pauses instead of resetting.
 */
export type SeasonPhase = 'base' | 'build' | 'peak' | 'taper';

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
    /**
     * Season thread-coverage overlay — only the Plan tab's season summary
     * passes this. Everywhere else the ball keeps its constant default
     * texture, so the mascot never becomes phase-aware app-wide.
     */
    seasonPhase?: SeasonPhase;
    className?: string;
}

// ── Palette constants ───────────────────────────────────────────────
// Temari-the-character is a hand-wound thread ball: a warm neutral core
// (visible where thread coverage is sparse) wrapped in jewel-toned bands.

const CORE = '#F2E4C9';
const CORE_SHADE = '#DCC097';
const CORE_DARK = '#BE9759';
const EYE = '#1A1812';
const CHEEK = '#E89B8E';
const OUTLINE = '#3b2f1f';
const DEFAULT_BAND = '#0e7a4c';

const THREAD_JEWEL = {
    crimson: '#9A2B3D',
    gold: '#D9A53C',
    indigo: '#4A3B8C',
    emerald: '#1F6E4A',
    violet: '#6B3FA0',
};

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

const MEDAL_PALETTE: Record<
    string,
    { coin: string; glow: string; ring: boolean }
> = {
    pertama: { coin: '#C77F4A', glow: '#E0A06E', ring: false },
    emas: { coin: '#D9B23A', glow: '#F5D365', ring: false },
    perak: { coin: '#A8B4C0', glow: '#C8D4E0', ring: true },
    platina: { coin: '#B8D4E8', glow: '#D8F0FF', ring: true },
};

// ── Kaus (shirt band) palette ────────────────────────────────────────

const KAUS_PALETTE: Record<
    string,
    { fill: string; trim: string; emblem: string }
> = {
    pemula: { fill: '#E8E4DC', trim: '#CCC8C0', emblem: '#A09888' },
    pagi: { fill: '#F5D365', trim: '#D9B23A', emblem: '#B8941E' },
    hujan: { fill: '#5E89B5', trim: '#4A6F94', emblem: '#8CB4D8' },
    legendaris: { fill: '#D9B23A', trim: '#B8941E', emblem: '#F5D365' },
};

// ── Celana (shorts band) palette ──────────────────────────────────────

const CELANA_PALETTE: Record<string, { fill: string; stripe: string }> = {
    ringan: { fill: '#3d362a', stripe: '#6e6452' },
    jarak: { fill: '#07492d', stripe: '#d9764a' },
    split: { fill: '#2c355c', stripe: '#e8a076' },
    maraton: { fill: '#1a1812', stripe: '#d9b23a' },
};

// ── Sepatu (trailing ribbon) palette ──────────────────────────────────

const SEPATU_PALETTE: Record<
    string,
    { upper: string; sole: string; accent: string }
> = {
    basic: { upper: '#A09888', sole: '#ffffff', accent: '#6e6452' },
    cepat: { upper: '#d9764a', sole: '#ffffff', accent: '#b75f37' },
    tahan: { upper: '#3d5a4f', sole: '#ffffff', accent: '#6b8e6f' },
    legendaris: { upper: '#D9B23A', sole: '#ffffff', accent: '#F5D365' },
};

// ── Aura palette ────────────────────────────────────────────────────

const AURA_PALETTE: Record<
    string,
    { inner: string; mid: string; outer: string }
> = {
    pemanasan: { inner: '#F5D365', mid: '#D9B23A', outer: '#D9B23A' },
    gerah: { inner: '#E8A076', mid: '#C4623F', outer: '#C4623F' },
    tenang: { inner: '#8CB4D8', mid: '#5E89B5', outer: '#5E89B5' },
    jagoan: { inner: '#D8F0FF', mid: '#D9B23A', outer: '#B8941E' },
    angin: { inner: '#D6F0EC', mid: '#6FBAAE', outer: '#5FA79B' },
};

// ── Pose configs ────────────────────────────────────────────────────
// No limbs to swing on a ball, so pose variation moves to: a slight face
// tilt for character, and the root-level bounce/roll/rock animation
// (defined in app.css, unchanged) doing the heavy lifting.

const FACE_TILT: Record<TemariPose, number> = {
    proud: 0,
    pumped: -3,
    excited: 4,
    holding: 0,
    reading: 6,
    wobble: -8,
    observational: -2,
    glow: 0,
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

// Resting-tendril splay per pose — how far the two thread-tail stubs at the
// ball's base flick outward. Purely cosmetic since held poses override the
// tendrils entirely to grip the book instead.
const TENDRIL_SPLAY: Record<TemariPose, number> = {
    proud: 6,
    pumped: 10,
    excited: 14,
    holding: 0,
    reading: 0,
    wobble: -10,
    observational: 4,
    glow: 8,
};

// Poses where Temari grips a book with both tendrils. The resting-tendril
// stubs are replaced by a curled grip around the book instead.
const HELD_POSES = new Set<TemariPose>(['holding', 'reading']);

// ── Season coverage configs ────────────────────────────────────────
// Discrete, not procedural: each phase is a fixed set of thread bands at
// increasing density/saturation. Taper keeps peak's full band set and adds
// a rested shine rather than removing coverage.

interface SeasonCoverageConfig {
    angles: number[];
    width: number;
    opacity: number;
    shine?: boolean;
}

const FULL_COVERAGE = {
    angles: [-30, -10, 15, 40, 65, 85],
    width: 3,
    opacity: 0.85,
};

const SEASON_COVERAGE: Record<SeasonPhase, SeasonCoverageConfig> = {
    base: { angles: [20], width: 1.4, opacity: 0.3 },
    build: { angles: [-15, 25, 60], width: 2.2, opacity: 0.55 },
    peak: FULL_COVERAGE,
    // Taper keeps the full band set peak already reaches (no visual
    // regression) and just adds the rested shine.
    taper: { ...FULL_COVERAGE, shine: true },
};

const SEASON_COLORS = [
    THREAD_JEWEL.crimson,
    THREAD_JEWEL.gold,
    THREAD_JEWEL.indigo,
    THREAD_JEWEL.emerald,
    THREAD_JEWEL.violet,
];

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
    seasonPhase,
    className,
}: Readonly<TemariProtoProps>) {
    // Ball form: a single sphere (cx 60, cy 64, r 40) with a face on its front
    // surface, thread bands wrapped around it, and short tendril stubs at its
    // base. ViewBox leaves room above for the headband bow / medal loop and
    // below for the trailing shoe ribbon + ground shadow.
    const viewW = 120;
    const viewH = 140;
    const aspectHeight = (size * viewH) / viewW;

    const headbandKey = equipped?.headband ?? null;
    const hb =
        HEADBAND_PALETTE[headbandKey ?? 'ember'] ?? HEADBAND_PALETTE.ember;

    const medalKey =
        !equipped?.medal || equipped.medal === 'none' ? null : equipped.medal;
    const medal = medalKey
        ? (MEDAL_PALETTE[medalKey] ?? MEDAL_PALETTE.pertama)
        : null;

    const auraKey = resolveAuraKey(equipped);
    const showAura = auraKey !== null;
    const auraColors = auraKey
        ? (AURA_PALETTE[auraKey] ?? AURA_PALETTE.pemanasan)
        : null;

    const showSparkle = SPARKLE_POSES.has(pose) || showAura;
    const kausKey = equipped?.kaus ?? null;
    const kausColors = kausKey
        ? (KAUS_PALETTE[kausKey] ?? KAUS_PALETTE.pemula)
        : null;

    const celanaKey = equipped?.celana ?? null;
    const celanaColors = celanaKey
        ? (CELANA_PALETTE[celanaKey] ?? CELANA_PALETTE.ringan)
        : null;

    const sepatuKey = equipped?.sepatu ?? null;
    const sepatuColors = sepatuKey
        ? (SEPATU_PALETTE[sepatuKey] ?? SEPATU_PALETTE.basic)
        : null;

    const faceTilt = FACE_TILT[pose];
    const eyeShape = EYE_BY_POSE[pose];
    const mouthShape = MOUTH_BY_POSE[pose];
    const tendrilSplay = TENDRIL_SPLAY[pose];
    const held = HELD_POSES.has(pose);
    const emblemColor = headbandKey ? hb.band : CORE_SHADE;

    // The clip circle's geometry never varies between instances, so a static
    // id (matching the other invariant defs below) is safe — unlike the aura
    // gradient, which really does differ per instance and needs useId.
    const ballClipId = `temari-ball-clip-${useId()}`;
    // Same reasoning, and same multi-instance-page caveat as the aura
    // gradient below (see AuraLayer's comment): several TemariProto
    // instances render on one page (e.g. the Accessories catalog grid), so
    // this needs useId too — a literal id would let the DOM's first-mounted
    // mask silently win for every instance, which happens to be harmless
    // here since the geometry is identical, but is fragile to rely on.
    const faceMaskId = `temari-face-mask-${useId()}`;

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
                viewBox={`0 -4 ${viewW} ${viewH}`}
                width={size}
                height={aspectHeight}
                style={{ display: 'block', overflow: 'visible' }}
                aria-hidden
            >
                <defs>
                    <filter
                        id="temari-shadow"
                        x="-20%"
                        y="-10%"
                        width="140%"
                        height="140%"
                    >
                        <feDropShadow
                            dx="0"
                            dy="3"
                            stdDeviation="3"
                            floodColor="#3b2f1f"
                            floodOpacity="0.15"
                        />
                    </filter>
                    <radialGradient id="ball-grad" cx="42%" cy="32%" r="65%">
                        <stop offset="0%" stopColor="#FFF3DD" />
                        <stop offset="55%" stopColor={CORE} />
                        <stop offset="82%" stopColor={CORE_SHADE} />
                        <stop offset="100%" stopColor={CORE_DARK} />
                    </radialGradient>
                    <radialGradient
                        id="ball-highlight"
                        cx="50%"
                        cy="30%"
                        r="40%"
                    >
                        <stop offset="0%" stopColor="#fff" stopOpacity="0.35" />
                        <stop offset="100%" stopColor="#fff" stopOpacity="0" />
                    </radialGradient>
                    {/* Garment form-shading overlay — top highlight → bottom shade, works on any fill */}
                    <linearGradient
                        id="garment-shade"
                        x1="18%"
                        y1="0%"
                        x2="62%"
                        y2="100%"
                    >
                        <stop offset="0%" stopColor="#fff" stopOpacity="0.16" />
                        <stop offset="34%" stopColor="#fff" stopOpacity="0" />
                        <stop offset="64%" stopColor="#000" stopOpacity="0" />
                        <stop
                            offset="100%"
                            stopColor="#000"
                            stopOpacity="0.22"
                        />
                    </linearGradient>
                    {/* Metallic coin sheen — diagonal highlight → shade */}
                    <linearGradient
                        id="coin-sheen"
                        x1="0%"
                        y1="0%"
                        x2="100%"
                        y2="100%"
                    >
                        <stop offset="0%" stopColor="#fff" stopOpacity="0.6" />
                        <stop offset="44%" stopColor="#fff" stopOpacity="0" />
                        <stop
                            offset="100%"
                            stopColor="#000"
                            stopOpacity="0.3"
                        />
                    </linearGradient>
                    <radialGradient
                        id="cheek-blush-l"
                        cx="50%"
                        cy="50%"
                        r="50%"
                    >
                        <stop offset="0%" stopColor={CHEEK} stopOpacity="0.7" />
                        <stop offset="100%" stopColor={CHEEK} stopOpacity="0" />
                    </radialGradient>
                    <radialGradient
                        id="cheek-blush-r"
                        cx="50%"
                        cy="50%"
                        r="50%"
                    >
                        <stop offset="0%" stopColor={CHEEK} stopOpacity="0.7" />
                        <stop offset="100%" stopColor={CHEEK} stopOpacity="0" />
                    </radialGradient>
                    <clipPath id={ballClipId}>
                        <circle cx="60" cy="64" r="40" />
                    </clipPath>
                    <mask id={faceMaskId}>
                        <rect
                            x="0"
                            y="0"
                            width={viewW}
                            height={viewH}
                            fill="#fff"
                        />
                        <ellipse cx="60" cy="61" rx="19" ry="15" fill="#000" />
                    </mask>
                </defs>

                {/* Aura (behind everything) */}
                {showAura && auraColors && <AuraLayer colors={auraColors} />}

                {/* Ground shadow */}
                <ellipse
                    cx="60"
                    cy="130"
                    rx="26"
                    ry="4"
                    fill={EYE}
                    opacity="0.1"
                />

                {/* Character group with drop shadow (skipped inside 3D-tilt cards) */}
                <g filter={dropShadow ? 'url(#temari-shadow)' : undefined}>
                    {/* Resting thread-tail stubs, replaced by a book grip in held poses */}
                    {!held && <Tendrils splay={tendrilSplay} />}

                    {/* The ball itself, with its thread coverage clipped to its silhouette */}
                    <circle
                        cx="60"
                        cy="64"
                        r="40"
                        fill="url(#ball-grad)"
                        stroke={CORE_SHADE}
                        strokeWidth="1.2"
                    />
                    <ellipse
                        cx="60"
                        cy="50"
                        rx="24"
                        ry="16"
                        fill="url(#ball-highlight)"
                    />
                    <g clipPath={`url(#${ballClipId})`}>
                        <g mask={`url(#${faceMaskId})`}>
                            {seasonPhase ? (
                                <SeasonCoverage phase={seasonPhase} />
                            ) : (
                                <DefaultThreadTexture />
                            )}
                        </g>
                        <ShirtBand colors={kausColors} emblem={emblemColor} />
                        <ShortsBand colors={celanaColors} />
                    </g>

                    {/* Face */}
                    <g transform={`rotate(${faceTilt} 60 64)`}>
                        <Cheeks />
                        <Eyes shape={eyeShape} />
                        <Mouth shape={mouthShape} />
                    </g>

                    {/* Headband — bow tied at the crown, only when equipped */}
                    {headbandKey && (
                        <HeadbandBow
                            band={hb.band}
                            legendary={headbandKey === 'legendaris'}
                        />
                    )}

                    {/* Medal — loop at the crown, coin on the front */}
                    {medal && <MedalLayer medal={medal} />}

                    {/* Trailing ribbon beneath the ball, suggesting motion */}
                    <ShoeRibbon colors={sepatuColors} />

                    {/* Tendrils gripping the book, drawn after the ball/bands so
                        they (and the book) sit in front */}
                    {held && <HoldingTendrils />}
                    {held && <HeldObject />}
                </g>

                {/* Sparkles */}
                {showSparkle && <Sparkles accentHex={THREAD_JEWEL.gold} />}
            </svg>
        </div>
    );
}

// ── Aura ─────────────────────────────────────────────────────────────

function AuraLayer({
    colors,
}: Readonly<{ colors: { inner: string; mid: string; outer: string } }>) {
    // The gradient id must be unique per instance: it was document-global, so with
    // several TemariProto auras on one page (e.g. the accessory grid) every circle
    // resolved to the first-mounted aura's colors, misrepresenting the rest.
    const gradId = `temari-aura-grad-${useId()}`;
    return (
        <g
            style={{
                animation: 'temari-aura-pulse 2.4s ease-in-out infinite',
                transformOrigin: '60px 64px',
            }}
        >
            <defs>
                <radialGradient id={gradId}>
                    <stop
                        offset="0%"
                        stopColor={colors.inner}
                        stopOpacity="0.7"
                    />
                    <stop
                        offset="60%"
                        stopColor={colors.mid}
                        stopOpacity="0.2"
                    />
                    <stop
                        offset="100%"
                        stopColor={colors.outer}
                        stopOpacity="0"
                    />
                </radialGradient>
            </defs>
            <circle
                cx="60"
                cy="64"
                r="70"
                fill={`url(#${gradId})`}
                opacity="0.7"
            />
        </g>
    );
}

// ── Default thread texture (no season phase given) ──────────────────

const DEFAULT_THREAD_BANDS = [
    {
        stroke: THREAD_JEWEL.crimson,
        ry: 12,
        width: 2.6,
        opacity: 0.5,
        rotate: -22,
    },
    { stroke: THREAD_JEWEL.gold, ry: 17, width: 2.2, opacity: 0.5, rotate: 20 },
    {
        stroke: THREAD_JEWEL.emerald,
        ry: 24,
        width: 1.8,
        opacity: 0.35,
        rotate: -52,
    },
    {
        stroke: THREAD_JEWEL.indigo,
        ry: 40,
        width: 1.6,
        opacity: 0.3,
        rotate: 70,
    },
];

function DefaultThreadTexture() {
    return (
        <>
            {DEFAULT_THREAD_BANDS.map((band) => (
                <ellipse
                    key={band.stroke}
                    cx="60"
                    cy="64"
                    rx="40"
                    ry={band.ry}
                    fill="none"
                    stroke={band.stroke}
                    strokeWidth={band.width}
                    opacity={band.opacity}
                    transform={`rotate(${band.rotate} 60 64)`}
                />
            ))}
        </>
    );
}

// ── Season coverage (Plan tab season summary only) ───────────────────

function SeasonCoverage({ phase }: Readonly<{ phase: SeasonPhase }>) {
    const cfg = SEASON_COVERAGE[phase];
    return (
        <>
            {cfg.angles.map((angle, i) => (
                <ellipse
                    key={angle}
                    cx="60"
                    cy="64"
                    rx="40"
                    ry={14 + i * 4}
                    fill="none"
                    stroke={SEASON_COLORS[i % SEASON_COLORS.length]}
                    strokeWidth={cfg.width}
                    opacity={cfg.opacity}
                    transform={`rotate(${angle} 60 64)`}
                />
            ))}
            {/* Taper keeps peak's full band set (no visual regression) and adds
                a rested sheen instead of removing coverage */}
            {cfg.shine && (
                <ellipse
                    cx="52"
                    cy="46"
                    rx="20"
                    ry="14"
                    fill="url(#ball-highlight)"
                    opacity="0.5"
                />
            )}
        </>
    );
}

// ── Shirt / shorts bands ──────────────────────────────────────────────

function ShirtBand({
    colors,
    emblem,
}: Readonly<{
    colors: { fill: string; trim: string; emblem: string } | null;
    emblem: string;
}>) {
    const c = colors ?? { fill: DEFAULT_BAND, trim: '#094d30', emblem };
    return (
        <g>
            <rect x="16" y="32" width="88" height="16" fill={c.fill} />
            <rect
                x="16"
                y="32"
                width="88"
                height="16"
                fill="url(#garment-shade)"
            />
            <line
                x1="16"
                y1="32.5"
                x2="104"
                y2="32.5"
                stroke={c.trim}
                strokeWidth="1.4"
            />
            <line
                x1="16"
                y1="47.5"
                x2="104"
                y2="47.5"
                stroke={c.trim}
                strokeWidth="1.4"
            />
            <RibLines y={[36, 44]} stroke={c.trim} />
            <circle
                cx="60"
                cy="40"
                r="3.2"
                fill={c.emblem}
                stroke={OUTLINE}
                strokeWidth="0.6"
            />
            <text
                x="60"
                y="41.3"
                textAnchor="middle"
                fontSize="3.2"
                fontWeight="bold"
                fill="#ffffff"
                fontFamily="sans-serif"
            >
                T
            </text>
        </g>
    );
}

function ShortsBand({
    colors,
}: Readonly<{ colors: { fill: string; stripe: string } | null }>) {
    const c = colors ?? { fill: '#07492d', stripe: '#6e6452' };
    return (
        <g>
            <rect x="16" y="84" width="88" height="14" fill={c.fill} />
            <rect
                x="16"
                y="84"
                width="88"
                height="14"
                fill="url(#garment-shade)"
            />
            <rect x="20" y="84" width="2" height="14" fill={c.stripe} />
            <rect x="98" y="84" width="2" height="14" fill={c.stripe} />
            <RibLines y={[91]} stroke={c.stripe} />
        </g>
    );
}

// Faint horizontal rib lines suggesting a knit/weave rather than flat
// plastic — shared by ShirtBand and ShortsBand.
function RibLines({ y, stroke }: Readonly<{ y: number[]; stroke: string }>) {
    return (
        <>
            {y.map((yPos) => (
                <line
                    key={yPos}
                    x1="16"
                    y1={yPos}
                    x2="104"
                    y2={yPos}
                    stroke={stroke}
                    strokeWidth="0.5"
                    opacity="0.3"
                />
            ))}
        </>
    );
}

// ── Headband bow ────────────────────────────────────────────────────

function HeadbandBow({
    band,
    legendary,
}: Readonly<{ band: string; legendary: boolean }>) {
    return (
        <g transform="translate(60, 22)">
            <path
                d="M -10 0 Q -16 -6 -12 -10 Q -6 -8 0 -2 Z"
                fill={band}
                stroke={OUTLINE}
                strokeWidth="0.5"
                strokeOpacity="0.25"
                strokeLinejoin="round"
            />
            <path
                d="M 10 0 Q 16 -6 12 -10 Q 6 -8 0 -2 Z"
                fill={band}
                stroke={OUTLINE}
                strokeWidth="0.5"
                strokeOpacity="0.25"
                strokeLinejoin="round"
            />
            <circle
                cx="0"
                cy="-1"
                r="3"
                fill={band}
                stroke={OUTLINE}
                strokeWidth="0.4"
                strokeOpacity="0.25"
            />
            <path
                d="M -2 2 Q -6 8 -4 14"
                stroke={band}
                strokeWidth="2.2"
                fill="none"
                strokeLinecap="round"
            />
            <path
                d="M 2 2 Q 6 8 4 14"
                stroke={band}
                strokeWidth="2.2"
                fill="none"
                strokeLinecap="round"
            />
            {legendary && (
                <path
                    d="M 0 -14 l 1 -3 l 1 3 l 3 1 l -3 1 l -1 3 l -1 -3 l -3 -1 z"
                    fill="#fff"
                    opacity="0.95"
                />
            )}
        </g>
    );
}

// ── Medal ─────────────────────────────────────────────────────────────

function MedalLayer({
    medal,
}: Readonly<{ medal: { coin: string; glow: string; ring: boolean } }>) {
    return (
        <g>
            {/* Ribbon loop from the crown down to the coin — curved out to
                the ball's flanks, clear of the eyes/mouth and starting below
                the headband bow instead of colliding with it */}
            <path
                d="M 46 29 Q 34 55 53 77"
                fill="none"
                stroke="#A8512C"
                strokeWidth="2.6"
                strokeLinecap="round"
            />
            <path
                d="M 74 29 Q 86 55 67 77"
                fill="none"
                stroke="#C4623F"
                strokeWidth="2.6"
                strokeLinecap="round"
            />
            <g transform="translate(60, 78)">
                <circle
                    cx="0"
                    cy="0"
                    r="5.5"
                    fill={medal.coin}
                    stroke={OUTLINE}
                    strokeWidth="0.5"
                />
                <circle
                    cx="0"
                    cy="0"
                    r="5.5"
                    fill="none"
                    stroke="#fff"
                    strokeWidth="0.6"
                    opacity="0.35"
                />
                <circle
                    cx="0"
                    cy="0"
                    r="4.2"
                    fill="none"
                    stroke={OUTLINE}
                    strokeWidth="0.35"
                    opacity="0.22"
                />
                {/* Embossed star */}
                <path
                    d="M 0 -2.6 L 0.73 -1.1 L 2.7 -0.95 L 1.2 0.3 L 1.66 2.2 L 0 1.1 L -1.66 2.2 L -1.2 0.3 L -2.7 -0.95 L -0.73 -1.1 Z"
                    fill="#FFF8E8"
                    opacity="0.9"
                />
                <circle cx="0" cy="0" r="5.5" fill="url(#coin-sheen)" />
                <ellipse
                    cx="-2"
                    cy="-2.2"
                    rx="1.5"
                    ry="0.9"
                    fill="#fff"
                    opacity="0.55"
                    transform="rotate(-35 -2 -2.2)"
                />
                {medal.ring && (
                    <circle
                        cx="0"
                        cy="0"
                        r="8"
                        fill="none"
                        stroke={medal.glow}
                        strokeWidth="1.1"
                        opacity="0.55"
                    />
                )}
            </g>
        </g>
    );
}

// ── Trailing ribbon (shoes) ────────────────────────────────────────────

// Two flowing ribbon streamers picking up where the tendrils leave off —
// deliberately just two (not three) so the base reads as thread trailing
// behind the ball, not a tripod of legs. The tips taper into a small
// rounded sole rather than a stark dot, keeping the "shoe" read without
// looking like an eye.
function ShoeRibbon({
    colors,
}: Readonly<{
    colors: { upper: string; sole: string; accent: string } | null;
}>) {
    const shoe = colors ?? {
        upper: '#A09888',
        sole: '#ffffff',
        accent: '#6e6452',
    };
    return (
        <g>
            <path
                d="M 40 96 Q 33 108 29 120"
                stroke={shoe.upper}
                strokeWidth="3.2"
                fill="none"
                strokeLinecap="round"
            />
            <path
                d="M 80 96 Q 87 108 91 120"
                stroke={shoe.upper}
                strokeWidth="3.2"
                fill="none"
                strokeLinecap="round"
            />
            <ellipse
                cx="29"
                cy="121"
                rx="2.6"
                ry="1.6"
                fill={shoe.sole}
                stroke={shoe.accent}
                strokeWidth="0.4"
                transform="rotate(-18 29 121)"
            />
            <ellipse
                cx="91"
                cy="121"
                rx="2.6"
                ry="1.6"
                fill={shoe.sole}
                stroke={shoe.accent}
                strokeWidth="0.4"
                transform="rotate(18 91 121)"
            />
        </g>
    );
}

// ── Tendrils ──────────────────────────────────────────────────────────
// Short curled thread-tail stubs at the ball's base — enough to grip a
// small prop, not enough to read as limbs.

function Tendrils({ splay }: Readonly<{ splay: number }>) {
    return (
        <>
            <Tendril x={33} y={88} curl={1} splay={splay} />
            <Tendril x={87} y={88} curl={-1} splay={splay} />
        </>
    );
}

// Short, tucked-in thread-end stubs — deliberately brief so they read as
// wisps of unwound thread at the ball's base, not limbs. Kept unjointed
// (no terminal dot) so they hand off cleanly into the shoe ribbon below
// instead of reading as a separate jointed "leg" segment.
function Tendril({
    x,
    y,
    curl,
    splay,
}: Readonly<{ x: number; y: number; curl: number; splay: number }>) {
    return (
        <g transform={`translate(${x}, ${y}) rotate(${splay * curl * 0.6})`}>
            <path
                d={`M 0 0 Q ${3 * curl} 3 ${2 * curl} 7`}
                fill="none"
                stroke={CORE_DARK}
                strokeWidth="2.2"
                strokeLinecap="round"
            />
        </g>
    );
}

function HoldingTendrils() {
    return (
        <>
            <path
                d="M 31 90 Q 26 84 34 79 Q 40 76 46 78"
                fill="none"
                stroke={CORE_DARK}
                strokeWidth="2.6"
                strokeLinecap="round"
            />
            <path
                d="M 89 90 Q 94 84 86 79 Q 80 76 74 78"
                fill="none"
                stroke={CORE_DARK}
                strokeWidth="2.6"
                strokeLinecap="round"
            />
            <circle cx="46" cy="78" r="1.8" fill={CORE_DARK} />
            <circle cx="74" cy="78" r="1.8" fill={CORE_DARK} />
        </>
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
                cy="80"
                rx="22"
                ry="15"
                fill="url(#temari-book-glow)"
                style={{
                    animation: 'temari-breathe 3s ease-in-out infinite',
                    transformOrigin: '60px 80px',
                }}
            />
            {/* Open book — two pages meeting at the spine */}
            <path
                d="M 60 74 Q 49 71 46 73 L 46 87 Q 49 85.5 60 89 Z"
                fill="#FAF3E6"
                stroke={OUTLINE}
                strokeWidth="0.6"
                strokeLinejoin="round"
            />
            <path
                d="M 60 74 Q 71 71 74 73 L 74 87 Q 71 85.5 60 89 Z"
                fill="#FBF6EC"
                stroke={OUTLINE}
                strokeWidth="0.6"
                strokeLinejoin="round"
            />
            {/* Spine */}
            <path
                d="M 60 74 L 60 89"
                stroke={OUTLINE}
                strokeWidth="0.7"
                strokeLinecap="round"
            />
            {/* Text lines */}
            <path
                d="M 50 76.6 L 56 77.3 M 50 79.4 L 56 80 M 50 82.1 L 56 82.6"
                stroke="#C9BCA3"
                strokeWidth="0.5"
                strokeLinecap="round"
            />
            <path
                d="M 64 77.3 L 70 76.6 M 64 80 L 70 79.4 M 64 82.6 L 70 82.1"
                stroke="#C9BCA3"
                strokeWidth="0.5"
                strokeLinecap="round"
            />
        </g>
    );
}

// ── Face sub-components ───────────────────────────────────────────────

function Cheeks() {
    return (
        <>
            <ellipse cx="42" cy="59" rx="7" ry="5" fill="url(#cheek-blush-l)" />
            <ellipse cx="78" cy="59" rx="7" ry="5" fill="url(#cheek-blush-r)" />
        </>
    );
}

function Eyes({ shape }: Readonly<{ shape: EyeShape }>) {
    if (shape === 'big') {
        return (
            <>
                <circle cx="48" cy="55" r="4" fill={EYE} />
                <circle cx="72" cy="55" r="4" fill={EYE} />
                <circle cx="49.5" cy="53.5" r="1.5" fill="#fff" />
                <circle cx="73.5" cy="53.5" r="1.5" fill="#fff" />
            </>
        );
    }
    if (shape === 'side') {
        return (
            <>
                <circle cx="50" cy="55" r="3" fill={EYE} />
                <circle cx="74" cy="55" r="3" fill={EYE} />
            </>
        );
    }
    if (shape === 'sad') {
        return (
            <>
                <path
                    d="M 45 54 Q 48 58 51 54"
                    stroke={EYE}
                    strokeWidth="2"
                    fill="none"
                    strokeLinecap="round"
                />
                <path
                    d="M 69 54 Q 72 58 75 54"
                    stroke={EYE}
                    strokeWidth="2"
                    fill="none"
                    strokeLinecap="round"
                />
            </>
        );
    }
    return (
        <>
            <circle cx="48" cy="55" r="3" fill={EYE} />
            <circle cx="72" cy="55" r="3" fill={EYE} />
            <circle cx="49" cy="54" r="1" fill="#fff" />
            <circle cx="73" cy="54" r="1" fill="#fff" />
        </>
    );
}

function Mouth({ shape }: Readonly<{ shape: MouthShape }>) {
    if (shape === 'open') {
        return <ellipse cx="60" cy="69" rx="5" ry="4" fill={EYE} />;
    }
    if (shape === 'small') {
        return (
            <path
                d="M 56 67 Q 60 69 64 67"
                stroke={EYE}
                strokeWidth="1.4"
                fill="none"
                strokeLinecap="round"
            />
        );
    }
    if (shape === 'frown') {
        return (
            <path
                d="M 53 70 Q 60 64 67 70"
                stroke={EYE}
                strokeWidth="1.6"
                fill="none"
                strokeLinecap="round"
            />
        );
    }
    return (
        <path
            d="M 53 66 Q 60 72 67 66"
            stroke={EYE}
            strokeWidth="1.6"
            fill="none"
            strokeLinecap="round"
        />
    );
}

// ── Sparkles ──────────────────────────────────────────────────────────

function Sparkles({ accentHex }: Readonly<{ accentHex: string }>) {
    return (
        <g
            style={{
                animation: 'temari-spin-sparkle 6s linear infinite',
                transformOrigin: '60px 64px',
            }}
        >
            <path
                d="M 12 10 l 2 -6 l 2 6 l 6 2 l -6 2 l -2 6 l -2 -6 l -6 -2 z"
                fill="#D9B23A"
                opacity="0.85"
            />
            <path
                d="M 102 18 l 1.5 -4 l 1.5 4 l 4 1.5 l -4 1.5 l -1.5 4 l -1.5 -4 l -4 -1.5 z"
                fill={accentHex}
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
 * `equipped={{...}}` object doesn't force this SVG tree to rebuild.
 */
function equippedEqual(
    a: TemariEquipped | null,
    b: TemariEquipped | null,
): boolean {
    if (a === b) {
        return true;
    }
    if (a === null || b === null) {
        return false;
    }
    return (
        a.headband === b.headband &&
        a.medal === b.medal &&
        a.kaus === b.kaus &&
        a.celana === b.celana &&
        a.sepatu === b.sepatu &&
        a.aura === b.aura
    );
}

function propsEqual(
    a: Readonly<TemariProtoProps>,
    b: Readonly<TemariProtoProps>,
): boolean {
    return (
        a.pose === b.pose &&
        a.size === b.size &&
        a.tone === b.tone &&
        a.animate === b.animate &&
        a.dropShadow === b.dropShadow &&
        a.seasonPhase === b.seasonPhase &&
        a.className === b.className &&
        equippedEqual(a.equipped ?? null, b.equipped ?? null)
    );
}

export default memo(TemariProto, propsEqual);
