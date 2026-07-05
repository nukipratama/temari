import { DAYBREAK, hrZone } from '@/lib/chartTokens';
import { projectPolyline } from '@/lib/route';
import { RARITY_LABELS, RARITY_SYMBOL } from '@/lib/runcard';
import { moodSigilColor } from '@/lib/mood';
import type { CardEdition, Mood, Rarity, ZonePct } from '@/types/inertia';

const HR_ZONES = ['Z1', 'Z2', 'Z3', 'Z4', 'Z5'] as const;

/**
 * Deterministic, device-independent share-card renderer.
 *
 * Everything is drawn at a FIXED internal resolution (1080×1920 story /
 * 1080×1080 feed) regardless of the on-screen size, so the exported PNG is
 * pixel-identical on every device and the live <canvas> preview IS the export.
 * No html-to-image, no DOM capture, no font-fallback drift.
 */

/**
 * Flat, render-ready data a single card contributes to a share image. Lives
 * here (next to the canvas engine that consumes it) rather than in the modal
 * component, so the lib has no component dependency.
 */
export interface ShareKartuData {
    id: number;
    name: string;
    /** Signed public share URL (server-minted). The share fallback copies/shares this. */
    shareUrl: string;
    rarity: Rarity;
    /** The run's Temari mood, used as the card's "element/type". */
    mood: Mood;
    subtitle: string | null;
    date: string | null;
    km: string;
    durasi: string;
    pace: string | null;
    trimp: string;
    hr: string | null;
    /** Avg cadence label, e.g. "178 spm". */
    cadence: string | null;
    /** Fastest single km pace, e.g. "5:41/km". */
    fastestKm: string | null;
    /** Total elevation gain, e.g. "123 m". */
    ascent?: string | null;
    /** HR zone distribution (Z1..Z5 %) for the effort bar. Null hides it. */
    zonePct: ZonePct | null;
    location: string | null;
    weather: string | null;
    /** Wind label, e.g. "12 km/j", for the context strip. */
    wind?: string | null;
    tags: string[];
    /** Badge emoji emblems, parallel to tags, for the hero ability pips. */
    tagEmojis: string[];
    quote: string | null;
    /** Encoded route polyline for the card / route templates (optional). */
    polyline?: string | null;
    /** Run distance (km). Thins the route stroke on longer routes, mirroring RouteGlyph. */
    distanceKm?: number | null;
    /** Collector number within the rarity (optional). */
    edition?: CardEdition | null;
}

export type Format = 'story' | 'feed';
export type Layout = 'kartu' | 'rute';

export interface ShareCardConfig {
    kartu: ShareKartuData;
    layout: Layout;
    format: Format;
    /**
     * The full Temari mascot (with user accessories) pre-rendered to an image
     * from the live DOM. When provided, `drawHero` draws this instead of the
     * fallback flat bunny glyph.
     */
    temariImg?: HTMLImageElement | null;
}

const DIMS: Record<Format, { w: number; h: number }> = {
    story: { w: 1080, h: 1920 },
    feed: { w: 1080, h: 1080 },
};

const PAD = 92;

// Daybreak palette as literal hex (canvas can't read CSS vars). Brand hues
// reference the shared DAYBREAK bridge so they can't drift; the rest are
// canvas-only shades that mirror the @theme block in app.css.
const C = {
    horizon: DAYBREAK.horizon,
    horizonDeep: DAYBREAK.horizonDeep,
    ink: DAYBREAK.ink,
    ink2: '#3d362a',
    ink3: '#6e6452',
    cream: '#f6f1e8',
    creamDeep: '#eee7d6',
    sky: DAYBREAK.sky,
    skyDeep: DAYBREAK.skyDeep,
    surfaceCard: '#f6f1e8',
    surfaceSunken: '#efe8da',
    line: '#e3dccd',
    inkOnSky: '#b8ad97',
    // Vivid loot-ladder rarity — mirrors RARITY_HEX / the --color-rarity-* tokens.
    rarity: {
        common: '#7d8694',
        uncommon: '#2fb350',
        rare: '#2f81f7',
        epic: '#a855f7',
        legendary: '#f5a623',
    } as Record<string, string>,
};

interface Palette {
    isDark: boolean;
    text: string;
    name: string;
    meta: string;
    divider: string;
    quote: string;
}

// One look only: every template renders on dark navy (the old Inverted theme).
const PALETTE: Palette = {
    isDark: true,
    text: C.cream,
    name: C.horizon,
    meta: 'rgba(246,241,232,0.72)',
    divider: 'rgba(246,241,232,0.18)',
    quote: 'rgba(246,241,232,0.88)',
};

function paintBackground(ctx: CanvasRenderingContext2D, w: number, h: number): void {
    ctx.fillStyle = C.skyDeep;
    ctx.fillRect(0, 0, w, h);
}

function paintGlow(ctx: CanvasRenderingContext2D, cx: number, cy: number, r: number): void {
    const g = ctx.createRadialGradient(cx, cy, 0, cx, cy, r);
    g.addColorStop(0, 'rgba(232,160,118,0.34)');
    g.addColorStop(0.66, 'rgba(232,160,118,0)');
    ctx.fillStyle = g;
    ctx.beginPath();
    ctx.arc(cx, cy, r, 0, Math.PI * 2);
    ctx.fill();
}

function roundRectPath(ctx: CanvasRenderingContext2D, x: number, y: number, w: number, h: number, r: number): void {
    const radius = Math.min(r, w / 2, h / 2);
    ctx.beginPath();
    ctx.moveTo(x + radius, y);
    ctx.arcTo(x + w, y, x + w, y + h, radius);
    ctx.arcTo(x + w, y + h, x, y + h, radius);
    ctx.arcTo(x, y + h, x, y, radius);
    ctx.arcTo(x, y, x + w, y, radius);
    ctx.closePath();
}

// Ratios lifted from RouteGlyph's SVG markers (start dot r=3, finish ring
// r=3.2/strokeWidth=1.4 against its base stroke of 3.8), so the canvas markers
// stay proportional to whatever stroke width is actually drawn here.
const START_DOT_RATIO = 3 / 3.8;
const FINISH_RING_STROKE_RATIO = 1.4 / 3.8;

/**
 * Stroke a route polyline inside a box (x, y, w, h) using the shared
 * `projectPolyline` geometry (same normalization as the on-card RouteGlyph),
 * then mark the start (filled dot) and, for point-to-point routes, the finish
 * (hollow ring) — mirroring RouteGlyph's markers. `distanceKm` thins the
 * stroke on longer routes the same way RouteGlyph does, scaled to this
 * template's larger base `lineWidth`. Returns false (drew nothing) when
 * there's no drawable route, so callers fall back.
 */
function drawRoute(
    ctx: CanvasRenderingContext2D,
    polyline: string | null | undefined,
    box: { x: number; y: number; w: number; h: number },
    stroke: string,
    lineWidth: number,
    glow = false,
    distanceKm?: number | null,
): boolean {
    const projected = projectPolyline(polyline, box.w, box.h, lineWidth * 1.5, 240);
    if (projected === null) {
        return false;
    }

    // Same log2 thinning as RouteGlyph's `strokeWidth`, proportional to this
    // template's own base width instead of copying its literal 3.8/2.2/0.5.
    const strokeWidth =
        distanceKm != null && Number.isFinite(distanceKm)
            ? Math.max(lineWidth * (2.2 / 3.8), lineWidth - Math.log2(Math.max(distanceKm, 1)) * (lineWidth * (0.5 / 3.8)))
            : lineWidth;

    ctx.save();
    ctx.beginPath();
    projected.points.forEach(([px, py], i) => {
        const x = box.x + px;
        const y = box.y + py;
        if (i === 0) {
            ctx.moveTo(x, y);
        } else {
            ctx.lineTo(x, y);
        }
    });
    ctx.strokeStyle = stroke;
    ctx.lineWidth = strokeWidth;
    ctx.lineJoin = 'round';
    ctx.lineCap = 'round';
    if (glow) {
        // A soft halo in the route's own hue, then a crisp core on top so the
        // line lifts off the backdrop without smearing.
        ctx.shadowColor = stroke;
        ctx.shadowBlur = strokeWidth * 1.8;
        ctx.stroke();
        ctx.shadowBlur = 0;
    }
    ctx.stroke();

    const [startX, startY] = projected.points[0];
    const [endX, endY] = projected.points.at(-1) ?? [startX, startY];
    // Always mark both ends — green "go" start, red "stop" finish — so the run's
    // direction reads even on a loop (the markers sit close but stay distinct).
    const markR = strokeWidth * START_DOT_RATIO * 1.6;
    // Start: filled green dot with a white rim for contrast on the route hue.
    ctx.beginPath();
    ctx.fillStyle = '#ffffff';
    ctx.arc(box.x + startX, box.y + startY, markR + strokeWidth * 0.35, 0, Math.PI * 2);
    ctx.fill();
    ctx.beginPath();
    ctx.fillStyle = '#22b455';
    ctx.arc(box.x + startX, box.y + startY, markR, 0, Math.PI * 2);
    ctx.fill();
    // Finish: red hollow ring (white backing so it reads over the route).
    ctx.beginPath();
    ctx.strokeStyle = '#ffffff';
    ctx.lineWidth = strokeWidth * FINISH_RING_STROKE_RATIO * 2.4;
    ctx.arc(box.x + endX, box.y + endY, markR, 0, Math.PI * 2);
    ctx.stroke();
    ctx.beginPath();
    ctx.strokeStyle = '#ef4a34';
    ctx.lineWidth = strokeWidth * FINISH_RING_STROKE_RATIO * 1.6;
    ctx.arc(box.x + endX, box.y + endY, markR, 0, Math.PI * 2);
    ctx.stroke();

    ctx.restore();
    return true;
}

function wrapText(ctx: CanvasRenderingContext2D, text: string, maxWidth: number): string[] {
    const words = text.split(/\s+/);
    const lines: string[] = [];
    let line = '';
    for (const word of words) {
        const candidate = line ? `${line} ${word}` : word;
        if (ctx.measureText(candidate).width > maxWidth && line) {
            lines.push(line);
            line = word;
        } else {
            line = candidate;
        }
    }
    if (line) {
        lines.push(line);
    }
    return lines;
}

// Fonts are device-stable, so the load only needs to happen once; cache the
// promise so interactive preview repaints don't re-await it every knob change.
let fontsReady: Promise<void> | null = null;

function ensureFonts(): Promise<void> {
    if (!fontsReady) {
        const specs = [
            'italic 600 120px "Fraunces"',
            'italic 500 120px "Fraunces"',
            '700 120px "JetBrains Mono"',
            '500 120px "JetBrains Mono"',
            '600 120px "Plus Jakarta Sans"',
            '700 120px "Plus Jakarta Sans"',
            '600 120px "Oswald"',
            '700 120px "Oswald"',
        ];
        fontsReady = Promise.all(specs.map((s) => document.fonts.load(s)))
            .then(() => document.fonts.ready)
            .then(() => undefined)
            .catch(() => {
                /* fonts best-effort; canvas falls back gracefully */
            });
    }
    return fontsReady;
}

// Flat, canvas-safe port of BunnyGlyph in components/BrandMark.tsx (no
// gradients/highlights). Keep the core geometry in sync with that source.
function bunnySvg(tone: 'ink' | 'cream', bandHex: string = C.horizon): string {
    const isInk = tone === 'ink';
    const face = isInk ? C.ink : C.cream;
    const blush = isInk ? C.horizon : C.horizonDeep;
    const features = isInk ? C.cream : C.ink;
    return `<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><defs><clipPath id="b"><circle cx="50" cy="58" r="40"/></clipPath></defs><ellipse cx="32" cy="8" rx="9" ry="16" fill="${face}" transform="rotate(-12 32 8)"/><ellipse cx="68" cy="8" rx="9" ry="16" fill="${face}" transform="rotate(12 68 8)"/><ellipse cx="32" cy="10" rx="4" ry="9" fill="${blush}" transform="rotate(-12 32 10)"/><ellipse cx="68" cy="10" rx="4" ry="9" fill="${blush}" transform="rotate(12 68 10)"/><circle cx="50" cy="58" r="40" fill="${face}"/><g clip-path="url(#b)"><rect x="10" y="40" width="80" height="14" fill="${bandHex}"/></g><circle cx="38" cy="68" r="4.5" fill="${features}"/><circle cx="62" cy="68" r="4.5" fill="${features}"/><path d="M 44 80 Q 50 85 56 80" fill="none" stroke="${features}" stroke-width="2.4" stroke-linecap="round"/></svg>`;
}

function loadImage(src: string): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = reject;
        img.src = src;
    });
}

// Few tone/band combinations exist and each glyph never changes; cache every
// decoded image (keyed by tone + headband hex) so repeated repaints reuse it
// instead of re-encoding and re-decoding the SVG.
const bunnyCache: Record<string, HTMLImageElement> = {};

async function loadBunny(tone: 'ink' | 'cream', bandHex: string = C.horizon): Promise<HTMLImageElement | null> {
    const key = `${tone}:${bandHex}`;
    if (bunnyCache[key]) {
        return bunnyCache[key];
    }
    try {
        const img = await loadImage(`data:image/svg+xml;utf8,${encodeURIComponent(bunnySvg(tone, bandHex))}`);
        bunnyCache[key] = img;
        return img;
    } catch {
        return null;
    }
}

/** Star + rarity word pill. Returns its height so callers can flow below it. */
function drawRarityFlag(ctx: CanvasRenderingContext2D, x: number, y: number, rarity: string): number {
    const label = `★ ${(RARITY_LABELS[rarity as keyof typeof RARITY_LABELS] ?? rarity).toUpperCase()}`;
    ctx.font = '700 30px "JetBrains Mono"';
    ctx.letterSpacing = '3px';
    const tw = ctx.measureText(label).width;
    const padX = 28;
    const h = 56;
    const w = tw + padX * 2;
    roundRectPath(ctx, x, y, w, h, h / 2);
    ctx.fillStyle = C.horizon;
    ctx.fill();
    ctx.fillStyle = C.skyDeep;
    ctx.textBaseline = 'middle';
    ctx.textAlign = 'left';
    ctx.fillText(label, x + padX, y + h / 2 + 1);
    ctx.letterSpacing = '0px';
    return h;
}

/** Brand lockup (bunny + wordmark) right-aligned to `rightX`. */
function drawBrand(ctx: CanvasRenderingContext2D, rightX: number, y: number, isDark: boolean, bunny: HTMLImageElement | null): void {
    const size = 52;
    const gap = 14;
    ctx.font = '700 38px "JetBrains Mono"';
    ctx.textBaseline = 'middle';
    ctx.textAlign = 'left';
    const word = 'TemanLari';
    const wordW = ctx.measureText(word).width;
    const totalW = size + gap + wordW;
    const startX = rightX - totalW;
    if (bunny) {
        ctx.drawImage(bunny, startX, y, size, size);
    }
    ctx.fillStyle = isDark ? C.cream : C.ink;
    ctx.fillText(word, startX + size + gap, y + size / 2 + 1);
}

interface DrawCtx {
    ctx: CanvasRenderingContext2D;
    w: number;
    h: number;
    cfg: ShareCardConfig;
    pal: Palette;
    bunny: HTMLImageElement | null;
    /** Temari glyph with its headband tinted to the card's mood. */
    moodBunny: HTMLImageElement | null;
}

/** Bottom-left mono date stamp, shared by the poster and angka templates. */
function drawDateFooter(d: DrawCtx): void {
    const { ctx, h, cfg, pal } = d;
    const parts = [cfg.kartu.date?.replace('\n', ' · '), cfg.kartu.weather].filter(
        (part): part is string => part != null && part !== '',
    );
    if (parts.length === 0) {
        return;
    }
    ctx.font = '500 30px "JetBrains Mono"';
    ctx.letterSpacing = '2px';
    ctx.fillStyle = pal.meta;
    ctx.textAlign = 'left';
    ctx.textBaseline = 'alphabetic';
    ctx.fillText(parts.join(' · '), PAD, h - PAD);
    ctx.letterSpacing = '0px';
}

/** Route-map hero: the run's route as big poster art with name + KM + edition. */
function drawRute(d: DrawCtx): void {
    const { ctx, w, h, cfg, pal, bunny } = d;
    const k = cfg.kartu;
    const story = cfg.format === 'story';
    const rarityCol = C.rarity[k.rarity] ?? C.line;
    paintGlow(ctx, w / 2, h * 0.38, w * 0.5);
    drawBrand(ctx, w - PAD, PAD, pal.isDark, bunny);
    drawRarityFlag(ctx, PAD, PAD, k.rarity);

    // The route is the hero: bolder and rarity-glowing so it lifts off the navy.
    const box = { x: PAD, y: PAD + (story ? 110 : 88), w: w - PAD * 2, h: h * (story ? 0.4 : 0.36) };
    drawRoute(ctx, k.polyline, box, rarityCol, story ? 14 : 12, true, k.distanceKm);

    let y = box.y + box.h + (story ? 84 : 56);
    ctx.textAlign = 'left';
    ctx.textBaseline = 'alphabetic';
    ctx.font = `italic 600 ${story ? 88 : 64}px "Fraunces"`;
    ctx.fillStyle = pal.name;
    wrapText(ctx, `${k.name}.`, w - PAD * 2)
        .slice(0, 2)
        .forEach((ln) => {
            ctx.fillText(ln, PAD, y);
            y += story ? 92 : 72;
        });

    y += story ? 24 : 12;
    const kmSize = story ? 190 : 128;
    ctx.font = `700 ${kmSize}px "Oswald"`;
    ctx.fillStyle = rarityCol;
    ctx.fillText(k.km, PAD, y + kmSize * 0.8);
    const kmW = ctx.measureText(k.km).width;
    ctx.font = '700 40px "JetBrains Mono"';
    ctx.letterSpacing = '3px';
    ctx.fillStyle = pal.meta;
    ctx.fillText('KM', PAD + kmW + 20, y + kmSize * 0.5);
    ctx.letterSpacing = '0px';
    if (k.edition) {
        ctx.font = '600 48px "Oswald"';
        ctx.fillStyle = pal.meta;
        ctx.textAlign = 'right';
        ctx.fillText(`#${k.edition.index}/${k.edition.total}`, w - PAD, y + kmSize * 0.5);
    }

    // Fill the space below the KM hero with a stat row (both formats — the square
    // looked bare without it), then a badges row (story only) so the tall 9:16's
    // lower third carries the run's tags instead of dead navy.
    const sy = y + kmSize * 0.8 + (story ? 64 : 40);
    const cells = story ? heroStatCells(k) : heroStatCells(k).slice(0, 3);
    if (cells.length > 0) {
        drawHeroStatGrid(ctx, cells, PAD, sy, w - PAD * 2, story);
        if (story) {
            const statH = Math.ceil(cells.length / 3) * 70;
            drawBadgesRow(ctx, k, PAD, sy + statH + 44, w - PAD * 2, story);
        }
    }

    drawDateFooter(d);
}

function drawHeroShimmer(
    ctx: CanvasRenderingContext2D,
    cx: number, artY: number, cw: number, artH: number,
    rarity: Rarity, rarityCol: string,
): void {
    const g = ctx.createLinearGradient(cx, artY, cx + cw, artY + artH);
    if (rarity === 'legendary') {
        g.addColorStop(0, 'rgba(255,80,80,0.09)');
        g.addColorStop(0.2, 'rgba(255,200,80,0.09)');
        g.addColorStop(0.4, 'rgba(80,255,80,0.09)');
        g.addColorStop(0.6, 'rgba(80,180,255,0.09)');
        g.addColorStop(0.8, 'rgba(180,80,255,0.09)');
        g.addColorStop(1, 'rgba(255,80,80,0.09)');
    } else if (rarity === 'epic') {
        g.addColorStop(0, 'transparent');
        g.addColorStop(0.45, rarityCol + '33');
        g.addColorStop(0.5, 'rgba(255,255,255,0.18)');
        g.addColorStop(0.55, rarityCol + '33');
        g.addColorStop(1, 'transparent');
    } else {
        // Common / uncommon / rare get a single sheen stripe so every card
        // catches some light.
        g.addColorStop(0, 'transparent');
        g.addColorStop(0.46, rarityCol + '26');
        g.addColorStop(0.5, 'rgba(255,255,255,0.14)');
        g.addColorStop(0.54, rarityCol + '18');
        g.addColorStop(1, 'transparent');
    }
    ctx.fillStyle = g;
    ctx.globalCompositeOperation = 'soft-light';
    ctx.fillRect(cx, artY, cw, artH);
    ctx.globalCompositeOperation = 'source-over';
}

/**
 * Floating pills over the bright art window, one per corner: rarity chip (top-L,
 * "★ ISTIMEWA" in the rarity hue) so the tier reads over the map, TRIMP power
 * (top-R), and the edition number (bottom-L). The mascot owns the bottom-R.
 */
function drawHeroArtBadges(
    ctx: CanvasRenderingContext2D,
    k: ShareKartuData,
    box: { x: number; y: number; w: number; h: number },
    moodCol: string,
    rarityCol: string,
): void {
    const badgePad = 18;
    const badgeH = 48;
    const top = box.y + 18;
    const mid = top + badgeH / 2;
    const bottom = box.y + box.h - 18 - badgeH;
    const dark = 'rgba(22,27,51,0.82)';
    ctx.textBaseline = 'middle';

    // Top-left: rarity chip.
    ctx.font = '700 26px "JetBrains Mono"';
    ctx.letterSpacing = '2px';
    const rarText = RARITY_SYMBOL[k.rarity] + ' ' + RARITY_LABELS[k.rarity].toUpperCase();
    const rarW = ctx.measureText(rarText).width + badgePad * 2;
    roundRectPath(ctx, box.x + 18, top, rarW, badgeH, badgeH / 2);
    ctx.fillStyle = dark;
    ctx.fill();
    ctx.fillStyle = rarityCol;
    ctx.textAlign = 'left';
    ctx.fillText(rarText, box.x + 18 + badgePad, mid + 1);
    ctx.letterSpacing = '0px';

    // Top-right: TRIMP power (mood dot + number).
    ctx.font = '700 28px "JetBrains Mono"';
    const trimpText = String(k.trimp);
    const trimpW = ctx.measureText(trimpText).width + badgePad * 2 + 30;
    const bx = box.x + box.w - 18 - trimpW;
    roundRectPath(ctx, bx, top, trimpW, badgeH, badgeH / 2);
    ctx.fillStyle = dark;
    ctx.fill();
    ctx.beginPath();
    ctx.arc(bx + badgePad + 9, mid, 9, 0, Math.PI * 2);
    ctx.fillStyle = moodCol;
    ctx.fill();
    ctx.fillStyle = C.cream;
    ctx.textAlign = 'left';
    ctx.fillText(trimpText, bx + badgePad + 26, mid + 1);

    // Bottom-left: edition number.
    if (k.edition) {
        ctx.font = '600 26px "JetBrains Mono"';
        const edText = '#' + String(k.edition.index) + '/' + String(k.edition.total);
        const edW = ctx.measureText(edText).width + badgePad * 2;
        roundRectPath(ctx, box.x + 18, bottom, edW, badgeH, badgeH / 2);
        ctx.fillStyle = dark;
        ctx.fill();
        ctx.fillStyle = C.cream;
        ctx.fillText(edText, box.x + 18 + badgePad, bottom + badgeH / 2 + 1);
    }
    ctx.textBaseline = 'alphabetic';
}

/** The bright art window: cream wash, route hero, corner bunny, floating badges. */
function drawHeroArtWindow(
    ctx: CanvasRenderingContext2D,
    k: ShareKartuData,
    mascot: HTMLImageElement | null,
    box: { x: number; y: number; w: number; h: number },
    rarityCol: string,
    moodCol: string,
    story: boolean,
): void {
    const r = 24;
    ctx.save();
    roundRectPath(ctx, box.x, box.y, box.w, box.h, r);
    ctx.clip();

    // Pearl backdrop: a light cream gradient with real top-to-bottom depth, so
    // the route reads with contrast instead of floating on flat off-white.
    const bg = ctx.createLinearGradient(box.x, box.y, box.x, box.y + box.h);
    bg.addColorStop(0, '#fcf9f3');
    bg.addColorStop(1, C.creamDeep);
    ctx.fillStyle = bg;
    ctx.fillRect(box.x, box.y, box.w, box.h);

    // A rarity glow up top gives the window its tier identity. Kept off-centre
    // and partial so it doesn't tint the whole surface (which would mute the
    // same-hue route), plus a faint mood echo in the opposite corner for warmth.
    const tierGlow = ctx.createRadialGradient(
        box.x + box.w * 0.3, box.y + box.h * 0.26, 0,
        box.x + box.w * 0.3, box.y + box.h * 0.26, box.h * 0.85,
    );
    tierGlow.addColorStop(0, rarityCol + '30');
    tierGlow.addColorStop(0.5, rarityCol + '12');
    tierGlow.addColorStop(1, 'rgba(0,0,0,0)');
    ctx.fillStyle = tierGlow;
    ctx.fillRect(box.x, box.y, box.w, box.h);

    const moodGlow = ctx.createRadialGradient(
        box.x + box.w * 0.82, box.y + box.h * 0.84, 0,
        box.x + box.w * 0.82, box.y + box.h * 0.84, box.h * 0.6,
    );
    moodGlow.addColorStop(0, moodCol + '22');
    moodGlow.addColorStop(1, 'rgba(0,0,0,0)');
    ctx.fillStyle = moodGlow;
    ctx.fillRect(box.x, box.y, box.w, box.h);

    // Route hero — bold + rarity-glow so it lifts off the pearl. Inset a touch so
    // it never crowds the corner companion or the floating badges.
    const routeBox = {
        x: box.x + box.w * 0.07,
        y: box.y + box.h * 0.12,
        w: box.w * 0.86,
        h: box.h * 0.78,
    };
    const hasRoute = drawRoute(ctx, k.polyline, routeBox, rarityCol, story ? 18 : 15, true, k.distanceKm);
    drawHeroShimmer(ctx, box.x, box.y, box.w, box.h, k.rarity, rarityCol);

    // Temari, drawn on top as a crisp character (mirrors the live Kartu's corner
    // companion) rather than a faint watermark. With a route present it hugs the
    // bottom-right corner; with no GPS it grows into the empty space as the hero.
    if (mascot) {
        const natW = mascot.naturalWidth || mascot.width || 1;
        const natH = mascot.naturalHeight || mascot.height || 1;
        const target = Math.round(box.h * (hasRoute ? 0.34 : 0.62));
        const mw = natW >= natH ? target : Math.round(target * (natW / natH));
        const mh = natW >= natH ? Math.round(target * (natH / natW)) : target;
        ctx.globalAlpha = hasRoute ? 0.92 : 0.6;
        if (hasRoute) {
            ctx.drawImage(mascot, box.x + box.w - mw * 0.86, box.y + box.h - mh * 0.98, mw, mh);
        } else {
            ctx.drawImage(mascot, box.x + (box.w - mw) / 2, box.y + (box.h - mh) / 2, mw, mh);
        }
        ctx.globalAlpha = 1;
    }
    ctx.restore();

    drawHeroArtBadges(ctx, k, box, moodCol, rarityCol);
}

/**
 * The dark stat block, mirroring the live Kartu full tier: rarity ribbon, name,
 * subtitle, KM hero, a labeled PACE · HR · CADENCE · DURASI · BEST grid, a Z1..Z5
 * HR-zone effort bar, badges, and (story) a flavor quote.
 *
 * Returns the total height it consumed from `box.y`. Pass `draw=false` to
 * measure without painting — `drawHero` uses that to size the art window to the
 * remaining space, so the block never reserves dead navy below its content.
 */
interface HeroBlock {
    ctx: CanvasRenderingContext2D;
    k: ShareKartuData;
    box: { x: number; y: number; w: number; h: number };
    rarityCol: string;
    story: boolean;
    /** false = measure only (advance the cursor without painting). */
    draw: boolean;
    /**
     * Extra vertical space injected just before the zone bar, used by the feed
     * layout to push the zone bar + quote group to the bottom of the square.
     */
    padBeforeZone?: number;
}

function drawHeroBlock(s: HeroBlock): number {
    // The rarity ribbon now floats on the art window, so the block leads with the
    // centred name; everything below is centre-aligned for a symmetric poster.
    let y = s.box.y + (s.story ? 22 : 18);
    y = heroNameRow(s, y);
    y = heroKmRow(s, y);
    y = heroBadgeClusterRow(s, y); // centred badge row below the KM hero
    y = heroStatGridRow(s, y);
    y = heroZoneBarRow(s, y);
    y = heroContextRow(s, y); // 📍 location · 💨 wind · 📅 date at the bottom
    return y - s.box.y;
}

/**
 * Special-move name in condensed Oswald, centred over the dark block. The wrap
 * count is identical in the measure + draw passes so sizing is stable.
 */
function heroNameRow(s: HeroBlock, y: number): number {
    const { ctx, k, box, story, draw } = s;
    const nameSize = story ? box.w * 0.099 : box.w * 0.084;
    ctx.font = `700 ${nameSize}px "Oswald"`;
    ctx.letterSpacing = '-1px'; // condensed + tight = athletic
    ctx.textAlign = 'center';
    const lines = wrapText(ctx, k.name.toUpperCase(), box.w - 28).slice(0, 2);
    const lineH = nameSize * 1.04;
    y += story ? 8 : 6;
    const firstBaseline = y + lineH;
    const lastBaseline = y + lineH * lines.length;
    if (draw) {
        ctx.fillStyle = C.cream;
        lines.forEach((ln, i) => ctx.fillText(ln, box.x + box.w / 2, firstBaseline + i * lineH));
    }
    ctx.letterSpacing = '0px';
    return lastBaseline + nameSize * 0.32;
}

/** KM hero number + "KM" suffix, centred as a group (number floods horizon). */
function heroKmRow(s: HeroBlock, y: number): number {
    const { ctx, k, box, story, draw } = s;
    const kmSize = story ? box.w * 0.165 : box.w * 0.14;
    const suffixSize = story ? 28 : 24;
    const gap = 16;
    y += kmSize * 0.92;
    if (draw) {
        ctx.font = `700 ${kmSize}px "Oswald"`;
        ctx.letterSpacing = '-1px';
        const kmW = ctx.measureText(k.km).width;
        ctx.letterSpacing = '0px';
        ctx.font = `700 ${suffixSize}px "JetBrains Mono"`;
        const sufW = ctx.measureText('KM').width;
        const startX = box.x + box.w / 2 - (kmW + gap + sufW) / 2;
        ctx.textAlign = 'left';
        ctx.textBaseline = 'alphabetic';
        ctx.font = `700 ${kmSize}px "Oswald"`;
        ctx.letterSpacing = '-1px';
        ctx.fillStyle = C.horizon;
        ctx.fillText(k.km, startX, y);
        ctx.letterSpacing = '0px';
        ctx.font = `700 ${suffixSize}px "JetBrains Mono"`;
        ctx.fillStyle = C.inkOnSky;
        ctx.fillText('KM', startX + kmW + gap, y);
    }
    return y;
}

/** A centred row (wraps if needed) of up to 4 badge pills below the KM hero. */
function heroBadgeClusterRow(s: HeroBlock, y: number): number {
    const { ctx, k, box, story, draw } = s;
    const tags = k.tags.slice(0, 4);
    if (tags.length === 0) {
        return y;
    }
    ctx.font = `500 ${story ? 28 : 24}px "JetBrains Mono"`;
    const pillH = story ? 52 : 44;
    const gap = story ? 12 : 10;
    const padX = 20;
    const pills = tags.map((tag, i) => ({
        label: (k.tagEmojis[i] ?? '✦') + ' ' + tag,
        w: ctx.measureText((k.tagEmojis[i] ?? '✦') + ' ' + tag).width + padX * 2,
    }));
    // Pack into centred rows within the block width.
    const rows: Array<typeof pills> = [];
    let cur: typeof pills = [];
    let curW = 0;
    for (const p of pills) {
        if (cur.length > 0 && curW + gap + p.w > box.w) {
            rows.push(cur);
            cur = [];
            curW = 0;
        }
        curW += (cur.length > 0 ? gap : 0) + p.w;
        cur.push(p);
    }
    if (cur.length > 0) {
        rows.push(cur);
    }
    y += story ? 30 : 24;
    if (draw) {
        let by = y;
        rows.forEach((row) => {
            const rowW = row.reduce((sum, p) => sum + p.w, 0) + gap * (row.length - 1);
            let bx = box.x + (box.w - rowW) / 2;
            row.forEach((p) => {
                drawBadgePill(ctx, p.label, bx, by, p.w, pillH, padX);
                bx += p.w + gap;
            });
            by += pillH + gap;
        });
    }
    return y + rows.length * pillH + (rows.length - 1) * gap;
}

function heroStatGridRow(s: HeroBlock, y: number): number {
    const { ctx, k, box, story, draw } = s;
    const cells = heroStatCells(k);
    if (cells.length === 0) {
        return y;
    }
    y += story ? 26 : 20;
    if (draw) {
        drawHeroStatGrid(ctx, cells, box.x, y, box.w, story);
    }
    return y + Math.ceil(cells.length / 3) * (story ? 70 : 58);
}

function heroZoneBarRow(s: HeroBlock, y: number): number {
    const { ctx, k, box, story, draw } = s;
    // Feed pushes the bottom group (zone bar + quote) down to fill the square.
    y += s.padBeforeZone ?? 0;
    if (!k.zonePct) {
        return y;
    }
    const barH = story ? 30 : 22;
    y += story ? 26 : 20;
    if (draw) {
        drawZoneBar(ctx, k.zonePct, box.x, y, box.w, barH);
    }
    return y + barH + (story ? 8 : 6);
}

/** A single translucent badge pill with left-aligned label. */
function drawBadgePill(
    ctx: CanvasRenderingContext2D,
    label: string,
    x: number,
    y: number,
    w: number,
    h: number,
    padX: number,
): void {
    roundRectPath(ctx, x, y, w, h, h / 2);
    ctx.fillStyle = 'rgba(246,241,232,0.10)';
    ctx.fill();
    ctx.fillStyle = 'rgba(246,241,232,0.85)';
    ctx.textAlign = 'left';
    ctx.textBaseline = 'middle';
    ctx.fillText(label, x + padX, y + h / 2 + 1);
}

/**
 * Horizontal, left-aligned badge row (wraps if needed) — used by the route
 * poster to fill the otherwise-empty space below its stat grid.
 */
function drawBadgesRow(
    ctx: CanvasRenderingContext2D,
    k: ShareKartuData,
    left: number,
    y: number,
    w: number,
    story: boolean,
): void {
    const tags = k.tags.slice(0, 4);
    if (tags.length === 0) {
        return;
    }
    ctx.font = `500 ${story ? 30 : 25}px "JetBrains Mono"`;
    const pillH = story ? 56 : 46;
    const gap = story ? 14 : 11;
    const padX = 20;
    let x = left;
    let by = y;
    tags.forEach((tag, i) => {
        const label = (k.tagEmojis[i] ?? '✦') + ' ' + tag;
        const pillW = ctx.measureText(label).width + padX * 2;
        if (x + pillW > left + w && x > left) {
            x = left;
            by += pillH + gap;
        }
        drawBadgePill(ctx, label, x, by, pillW, pillH, padX);
        x += pillW + gap;
    });
    ctx.textBaseline = 'alphabetic';
}

/**
 * Bottom context strip — 📍 location · 💨 wind · 📅 date + time, one white mono
 * line that grounds the run in where/when/conditions. Location can be long, so
 * it's truncated to whatever width the short wind + date tail leaves.
 */
function heroContextRow(s: HeroBlock, y: number): number {
    const { ctx, k, box, story, draw } = s;
    // Keep the clock time alongside the day (date is "5 Jul 2026\n06.30").
    const dateStr = k.date ? k.date.replace('\n', ' · ') : null;
    const parts = [
        k.location != null && k.location !== '' ? '📍 ' + k.location : null,
        k.wind ? '💨 ' + k.wind : null,
        dateStr ? '📅 ' + dateStr : null,
    ].filter((p): p is string => p != null);
    if (parts.length === 0) {
        return y;
    }
    y += story ? 40 : 30;
    if (draw) {
        ctx.font = `500 ${story ? 26 : 22}px "JetBrains Mono"`;
        ctx.fillStyle = C.cream;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'alphabetic';
        ctx.fillText(truncateToWidth(ctx, parts.join('   '), box.w), box.x + box.w / 2, y);
    }
    return y + (story ? 26 : 22);
}

/** Trim `text` (appending "…") until it fits `maxWidth` at the current font. */
function truncateToWidth(ctx: CanvasRenderingContext2D, text: string, maxWidth: number): string {
    if (ctx.measureText(text).width <= maxWidth) {
        return text;
    }
    let trimmed = text;
    while (trimmed.length > 3 && ctx.measureText(trimmed + '…').width > maxWidth) {
        trimmed = trimmed.slice(0, -1);
    }
    return trimmed + '…';
}


/**
 * PACE · HR · CADENCE · DURASI · BEST · ELEVASI cells, present-only. Elevation
 * gain is the 6th cell (under CADENCE, col 3 row 2); TRIMP stays as the floating
 * power badge over the art window, so it isn't shown twice. Date moves to the
 * bottom context strip.
 */
function heroStatCells(k: ShareKartuData): Array<{ label: string; value: string }> {
    const raw: Array<{ label: string; value: string | null }> = [
        { label: 'PACE', value: k.pace ? k.pace + '/km' : null },
        { label: 'HR', value: k.hr },
        { label: 'CADENCE', value: k.cadence },
        { label: 'DURASI', value: k.durasi },
        { label: 'BEST', value: k.fastestKm },
        { label: 'ELEVASI', value: k.ascent ?? null },
    ];
    return raw.filter((c): c is { label: string; value: string } => c.value != null && c.value !== '' && c.value !== '—');
}

/** A 3-column label-over-value stat grid starting at top `y`. */
function drawHeroStatGrid(
    ctx: CanvasRenderingContext2D,
    cells: Array<{ label: string; value: string }>,
    left: number,
    y: number,
    w: number,
    story: boolean,
): void {
    const colW = w / 3;
    const rowH = story ? 70 : 58;
    const labelSize = story ? 22 : 18;
    const valueSize = story ? 36 : 30;
    const maxValueW = colW - 16; // gutter so a wide value never bleeds into the next column
    cells.forEach((cell, i) => {
        // Centre each label + value within its column.
        const cx = left + (i % 3) * colW + colW / 2;
        const cy = y + Math.floor(i / 3) * rowH;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'alphabetic';
        ctx.font = `700 ${labelSize}px "JetBrains Mono"`;
        ctx.letterSpacing = '2px';
        ctx.fillStyle = C.inkOnSky;
        ctx.fillText(cell.label, cx, cy + labelSize);
        ctx.letterSpacing = '0px';
        // Shrink the value to fit its column so long values (e.g. "39 menit 10
        // detik") can't overlap the neighbouring cell.
        let vSize = valueSize;
        ctx.font = `700 ${vSize}px "JetBrains Mono"`;
        while (vSize > 18 && ctx.measureText(cell.value).width > maxValueW) {
            vSize -= 2;
            ctx.font = `700 ${vSize}px "JetBrains Mono"`;
        }
        ctx.fillStyle = C.cream;
        ctx.fillText(cell.value, cx, cy + labelSize + valueSize + 4);
    });
    ctx.textAlign = 'left';
}

/** Stacked Z1..Z5 effort bar with tiny labels, using the shared HR-zone hexes. */
function drawZoneBar(
    ctx: CanvasRenderingContext2D,
    zonePct: NonNullable<ShareKartuData['zonePct']>,
    left: number,
    y: number,
    w: number,
    barH: number,
): void {
    const total = HR_ZONES.reduce((sum, z) => sum + (zonePct[z] ?? 0), 0);
    if (total <= 0) {
        return;
    }
    // Clip to a pill so the segmented bar has rounded ends. No Z1..Z5 legend on
    // the share — the colour ramp reads on its own.
    ctx.save();
    roundRectPath(ctx, left, y, w, barH, barH / 2);
    ctx.clip();
    let x = left;
    HR_ZONES.forEach((zone) => {
        const pct = zonePct[zone] ?? 0;
        if (pct <= 0) {
            return;
        }
        const segW = (pct / total) * w;
        ctx.fillStyle = hrZone[zone];
        ctx.fillRect(x, y, segW, barH);
        x += segW;
    });
    ctx.restore();
}


/**
 * Dark-frame TCG hero: a dark navy card with a vivid rarity border, an inner
 * hairline + corner pips, a bright art window up top (big mascot watermark +
 * route hero + floating edition/TRIMP pills), and a dark stat block below
 * (rarity ribbon, nameplate, type line, KM + stats, badges). Mirrors the React
 * Kartu component.
 */
function drawHero(d: DrawCtx): void {
    const { ctx, w, h, cfg, moodBunny } = d;
    const k = cfg.kartu;
    const story = cfg.format === 'story';
    const rarityCol = C.rarity[k.rarity] ?? C.line;
    const moodCol = moodSigilColor(k.mood);

    paintGlow(ctx, w / 2, h * 0.36, w * 0.5);

    // The card fills the whole canvas edge-to-edge: no surrounding backdrop, the
    // rarity border hugs the frame. Rounded corners reveal the same navy, so it
    // reads as a full-bleed card rather than a floating one.
    const cx = 0;
    const cy = 0;
    const cw = w;
    const ch = h;
    const border = 12;
    const radius = 44;
    const framePad = border + 24;

    // Dark card body + vivid rarity border.
    roundRectPath(ctx, cx, cy, cw, ch, radius);
    ctx.fillStyle = C.skyDeep;
    ctx.fill();
    ctx.lineWidth = border;
    ctx.strokeStyle = rarityCol;
    roundRectPath(ctx, cx + border / 2, cy + border / 2, cw - border, ch - border, radius - border / 2);
    ctx.stroke();

    // Single clean rarity border only — the live Kartu has no inner hairline or
    // corner pips, so neither does the share card.

    // Inner content frame.
    const innerX = cx + framePad;
    const innerW = cw - framePad * 2;
    const innerTop = cy + framePad;
    const innerH = ch - framePad * 2;
    const blockGap = 22;

    const makeBlock = (y: number, draw: boolean, padBeforeZone = 0): HeroBlock => ({
        ctx,
        k,
        box: { x: innerX, y, w: innerW, h: 0 },
        rarityCol,
        story,
        draw,
        padBeforeZone,
    });

    // Both formats: art window on top (route hero + mascot), stat block below.
    // Measure the block, then size the art window to the remaining space but
    // CAP it (maxArtFrac) so a short stat block (e.g. after dropping the subtitle
    // + quote) can't balloon the route to fill the whole card. The stat block
    // bottom-anchors, so the capped slack becomes breathing room above it, not a
    // dead navy void. The square keeps a lower art floor so its dense stat block
    // (taller relative to the shorter canvas) always fits.
    const measuredBlockH = drawHeroBlock(makeBlock(innerTop, false)) + 20;
    // Cap the map height (maxArtFrac) so it doesn't dominate — the block gets the
    // rest, and any slack is injected before the zone bar so the effort bar +
    // context strip sink to the bottom instead of leaving dead navy under a huge
    // map. When the block is tall enough the art shrinks to fit it exactly.
    const maxArtFrac = story ? 0.52 : 0.5;
    const artH = Math.min(Math.round(innerH * maxArtFrac), innerH - measuredBlockH - blockGap);
    const slack = Math.max(0, innerH - artH - blockGap - measuredBlockH);
    drawHeroArtWindow(ctx, k, cfg.temariImg ?? moodBunny, { x: innerX, y: innerTop, w: innerW, h: artH }, rarityCol, moodCol, story);
    drawHeroBlock(makeBlock(innerTop + artH + blockGap, true, slack));
}

const TEMPLATES: Record<Layout, (d: DrawCtx) => void> = {
    kartu: drawHero,
    rute: drawRute,
};

/**
 * Draw the configured share card onto `canvas` at its fixed internal resolution.
 * Idempotent: safe to call on every config change to refresh the preview.
 */
export async function drawShareCard(canvas: HTMLCanvasElement, cfg: ShareCardConfig): Promise<void> {
    const { w, h } = DIMS[cfg.format];
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');
    if (!ctx) {
        return;
    }

    await ensureFonts();
    const pal = PALETTE;
    const bunny = await loadBunny('cream');
    const moodBunny = await loadBunny('ink', moodSigilColor(cfg.kartu.mood));

    ctx.clearRect(0, 0, w, h);
    paintBackground(ctx, w, h);

    const d: DrawCtx = { ctx, w, h, cfg, pal, bunny, moodBunny };
    TEMPLATES[cfg.layout](d);
}

/** Render the card and return it as a PNG blob (full internal resolution). */
export async function shareCardBlob(cfg: ShareCardConfig): Promise<Blob> {
    const canvas = document.createElement('canvas');
    await drawShareCard(canvas, cfg);
    return new Promise((resolve, reject) => {
        canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('toBlob failed'))), 'image/png');
    });
}
