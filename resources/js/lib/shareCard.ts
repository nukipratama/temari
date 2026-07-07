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
    /** Activity detail page URL used as the share fallback (copied/shared when native file sharing isn't available). */
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

// Every card gets the SAME bright border bloom regardless of rarity — unlike
// the in-app Kartu's `.kartu-glow`, which only lights up rare+. The share
// image is a standalone poster with no surrounding hero glow to lean on, so
// it needs its own consistent glow rather than a rarity-gated one.
const BORDER_GLOW_BLUR = 60;

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

/** Rounded rectangle with independent per-corner radii, for corner-attached chips. */
function roundRectPathCorners(
    ctx: CanvasRenderingContext2D,
    x: number, y: number, w: number, h: number,
    radii: { tl: number; tr: number; br: number; bl: number },
): void {
    const { tl, tr, br, bl } = radii;
    ctx.beginPath();
    ctx.moveTo(x + tl, y);
    ctx.lineTo(x + w - tr, y);
    ctx.arcTo(x + w, y, x + w, y + tr, tr);
    ctx.lineTo(x + w, y + h - br);
    ctx.arcTo(x + w, y + h, x + w - br, y + h, br);
    ctx.lineTo(x + bl, y + h);
    ctx.arcTo(x, y + h, x, y + h - bl, bl);
    ctx.lineTo(x, y + tl);
    ctx.arcTo(x, y, x + tl, y, tl);
    ctx.closePath();
}

/**
 * Full-bleed rounded card frame shared by every share template: a dark navy
 * body edge-to-edge with a vivid rarity border and the same bright inward
 * bloom on every rarity (matches the in-app Kartu's `.kartu-glow`). No
 * surrounding backdrop — rounded corners reveal the same navy, so it reads
 * as a full-bleed card rather than a floating one.
 */
function drawCardFrame(ctx: CanvasRenderingContext2D, w: number, h: number, rarityCol: string): void {
    const border = 12;
    const radius = 44;
    roundRectPath(ctx, 0, 0, w, h, radius);
    ctx.fillStyle = C.skyDeep;
    ctx.fill();
    ctx.lineWidth = border;
    ctx.strokeStyle = rarityCol;
    ctx.shadowColor = rarityCol;
    ctx.shadowBlur = BORDER_GLOW_BLUR;
    roundRectPath(ctx, border / 2, border / 2, w - border, h - border, radius - border / 2);
    ctx.stroke();
    ctx.shadowBlur = 0;
}

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

/** Sections in the rute text block that share the even `gapBonus` distribution. */
function ruteBlockSectionCount(k: ShareKartuData, story: boolean): number {
    const cells = story ? heroStatCells(k) : heroStatCells(k).slice(0, 3);
    return (
        2 // name + KM always render
        + (cells.length > 0 ? 1 : 0)
        + (story && k.tags.length > 0 ? 1 : 0)
    );
}

/** Name in italic Fraunces, up to 2 lines. Returns the block's new bottom edge. */
function ruteNameRow(ctx: CanvasRenderingContext2D, k: ShareKartuData, pal: Palette, w: number, story: boolean, draw: boolean, y: number): number {
    ctx.textAlign = 'left';
    ctx.textBaseline = 'alphabetic';
    ctx.font = `italic 600 ${story ? 88 : 64}px "Fraunces"`;
    const lines = wrapText(ctx, `${k.name}.`, w - PAD * 2).slice(0, 2);
    const lineH = story ? 92 : 72;
    if (draw) {
        ctx.fillStyle = pal.name;
        let ly = y;
        lines.forEach((ln) => {
            ctx.fillText(ln, PAD, ly);
            ly += lineH;
        });
    }
    return y + lineH * lines.length;
}

/** KM hero + "KM" suffix + edition, left-aligned. Returns the row's bottom edge. */
function ruteKmRow(ctx: CanvasRenderingContext2D, k: ShareKartuData, pal: Palette, w: number, rarityCol: string, story: boolean, draw: boolean, y: number, gapBonus: number): number {
    y += (story ? 24 : 12) + gapBonus;
    const kmSize = story ? 190 : 128;
    if (draw) {
        ctx.font = `700 ${kmSize}px "Oswald"`;
        ctx.fillStyle = rarityCol;
        ctx.textAlign = 'left';
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
    }
    return y + kmSize * 0.8;
}

/** Stat row (single row for feed, up to 2 for story). Returns the row's bottom edge. */
function ruteStatGridRow(ctx: CanvasRenderingContext2D, k: ShareKartuData, w: number, story: boolean, draw: boolean, y: number, gapBonus: number): number {
    const cells = story ? heroStatCells(k) : heroStatCells(k).slice(0, 3);
    if (cells.length === 0) {
        return y;
    }
    y += (story ? 64 : 40) + gapBonus;
    if (draw) {
        drawHeroStatGrid(ctx, cells, PAD, y, w - PAD * 2, story);
    }
    return y + Math.ceil(cells.length / 3) * (story ? HERO_STAT_ROW_H.story : HERO_STAT_ROW_H.feed);
}

/** Badges row — story only, so the tall 9:16's lower third carries the run's tags. */
function ruteBadgesRow(ctx: CanvasRenderingContext2D, k: ShareKartuData, w: number, story: boolean, draw: boolean, y: number, gapBonus: number): number {
    const tags = k.tags.slice(0, 4);
    if (!story || tags.length === 0) {
        return y;
    }
    y += 44 + gapBonus;
    const pillH = 56;
    const gap = 14;
    const padX = 20;
    ctx.font = `500 30px "JetBrains Mono"`;
    const rows = packPillRows(measurePillSpecs(ctx, tags, k.tagEmojis, padX), w - PAD * 2, gap);
    if (draw) {
        drawBadgesRow(ctx, k, PAD, y, w - PAD * 2, story);
    }
    return y + rows.length * pillH + (rows.length - 1) * gap;
}

/** Measures or draws the whole rute text block (name → KM → stats → badges). */
function drawRuteBlock(ctx: CanvasRenderingContext2D, k: ShareKartuData, pal: Palette, w: number, rarityCol: string, story: boolean, draw: boolean, y: number, gapBonus: number): number {
    y = ruteNameRow(ctx, k, pal, w, story, draw, y);
    y = ruteKmRow(ctx, k, pal, w, rarityCol, story, draw, y, gapBonus);
    y = ruteStatGridRow(ctx, k, w, story, draw, y, gapBonus);
    y = ruteBadgesRow(ctx, k, w, story, draw, y, gapBonus);
    return y;
}

/** Route-map hero: the run's route as big poster art with name + KM + edition.
 *  Measures the text block's natural height, caps the map at a max fraction of
 *  the available space, then spreads any leftover slack evenly across the
 *  block's sections — mirrors `drawHero`'s art-window/stat-block split so a
 *  sparse card (no badges, no edition) fills the canvas instead of leaving a
 *  dead gap at the bottom. */
function drawRute(d: DrawCtx): void {
    const { ctx, w, h, cfg, pal, bunny } = d;
    const k = cfg.kartu;
    const story = cfg.format === 'story';
    const rarityCol = C.rarity[k.rarity] ?? C.line;
    paintGlow(ctx, w / 2, h * 0.38, w * 0.5);
    drawCardFrame(ctx, w, h, rarityCol);
    drawBrand(ctx, w - PAD, PAD, pal.isDark, bunny);
    drawRarityFlag(ctx, PAD, PAD, k.rarity);

    const topOffset = PAD + (story ? 110 : 88);
    const bottomReserve = 70; // room for the date footer above `h - PAD`
    const availableH = h - topOffset - bottomReserve;
    const routeGap = story ? 84 : 56; // fixed gap between the map and the text block

    const measuredBlockH = drawRuteBlock(ctx, k, pal, w, rarityCol, story, false, 0, 0);
    const maxRouteFrac = story ? 0.4 : 0.36;
    const routeH = Math.min(Math.round(availableH * maxRouteFrac), availableH - routeGap - measuredBlockH);
    const slack = Math.max(0, availableH - routeH - routeGap - measuredBlockH);
    const gapBonus = slack / ruteBlockSectionCount(k, story);

    // The route is the hero: bolder and rarity-glowing so it lifts off the navy.
    const box = { x: PAD, y: topOffset, w: w - PAD * 2, h: routeH };
    drawRoute(ctx, k.polyline, box, rarityCol, story ? 14 : 12, true, k.distanceKm);

    drawRuteBlock(ctx, k, pal, w, rarityCol, story, true, box.y + box.h + routeGap, gapBonus);

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
    const pad = 26;
    const h = 60;
    const innerR = 20; // the free (inner) corner rounds off; outer stays square + clipped
    const dark = C.skyDeep;
    const midTop = box.y + h / 2;
    ctx.textBaseline = 'middle';

    // Top-left: rarity chip, square outer corner clipped flush into the window.
    ctx.font = '700 29px "Plus Jakarta Sans"';
    ctx.letterSpacing = '1px';
    const rarText = RARITY_SYMBOL[k.rarity] + ' ' + RARITY_LABELS[k.rarity].toUpperCase();
    const rarW = ctx.measureText(rarText).width + pad * 2;
    roundRectPathCorners(ctx, box.x, box.y, rarW, h, { tl: 0, tr: 0, br: innerR, bl: 0 });
    ctx.fillStyle = dark;
    ctx.fill();
    ctx.fillStyle = rarityCol;
    ctx.textAlign = 'left';
    ctx.fillText(rarText, box.x + pad, midTop + 1);
    ctx.letterSpacing = '0px';

    // Top-right: TRIMP power (mood dot + number).
    ctx.font = '700 32px "Plus Jakarta Sans"';
    const trimpText = String(k.trimp);
    const trimpW = ctx.measureText(trimpText).width + pad * 2 + 34;
    const tx = box.x + box.w - trimpW;
    roundRectPathCorners(ctx, tx, box.y, trimpW, h, { tl: 0, tr: 0, br: 0, bl: innerR });
    ctx.fillStyle = dark;
    ctx.fill();
    ctx.beginPath();
    ctx.arc(tx + pad + 10, midTop, 10, 0, Math.PI * 2);
    ctx.fillStyle = moodCol;
    ctx.fill();
    ctx.fillStyle = C.cream;
    ctx.textAlign = 'left';
    ctx.fillText(trimpText, tx + pad + 30, midTop + 1);

    // Bottom-left: edition number.
    if (k.edition) {
        ctx.font = '700 28px "Plus Jakarta Sans"';
        const edText = '#' + String(k.edition.index) + '/' + String(k.edition.total);
        const edW = ctx.measureText(edText).width + pad * 2;
        const ey = box.y + box.h - h;
        roundRectPathCorners(ctx, box.x, ey, edW, h, { tl: 0, tr: innerR, br: 0, bl: 0 });
        ctx.fillStyle = dark;
        ctx.fill();
        ctx.fillStyle = C.cream;
        ctx.fillText(edText, box.x + pad, ey + h / 2 + 1);
    }
    ctx.textBaseline = 'alphabetic';
}

/** The bright art window: cream wash, route hero, corner brand mark, floating badges. */
function drawHeroArtWindow(
    ctx: CanvasRenderingContext2D,
    k: ShareKartuData,
    bunny: HTMLImageElement | null,
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
    // it never crowds the brand mark or the floating badges.
    const routeBox = {
        x: box.x + box.w * 0.07,
        y: box.y + box.h * 0.12,
        w: box.w * 0.86,
        h: box.h * 0.78,
    };
    drawRoute(ctx, k.polyline, routeBox, rarityCol, story ? 18 : 15, true, k.distanceKm);
    drawHeroShimmer(ctx, box.x, box.y, box.w, box.h, k.rarity, rarityCol);

    // Brand mark (bunny + wordmark), tucked into the map's bottom-right corner
    // instead of a big Temari mascot watermark — a quiet signature rather than
    // a character floating over the route.
    const brandPad = 20;
    drawBrand(ctx, box.x + box.w - brandPad, box.y + box.h - 52 - brandPad, false, bunny);

    // Draw the corner chips INSIDE the clip so their square outer corners are
    // clipped to the window radius (fills the corner, no pearl sliver).
    drawHeroArtBadges(ctx, k, box, moodCol, rarityCol);
    ctx.restore();
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
     * Even breathing room added before every section, so the block fills the space
     * under a shorter map with a consistent rhythm instead of one big gap. Zero in
     * the measure pass (natural height); `drawHero` sets it to slack / sectionCount
     * for the draw pass.
     */
    gapBonus?: number;
}

/**
 * How many of the block's sections will actually render for this card+format,
 * so `gapBonus` divides the leftover space across only the rows that draw
 * (name + KM always render; badges/stat-grid/zone-bar/context row are each
 * conditional on their own data). Getting this wrong under-counts and leaves
 * unfilled slack at the bottom for sparser cards instead of an even rhythm.
 */
function heroBlockSectionCount(k: ShareKartuData, story: boolean): number {
    const statCells = story ? heroStatCells(k) : heroStatCells(k).slice(0, 3);
    const hasContext = (k.location != null && k.location !== '') || k.wind != null || k.date != null;
    return (
        2 // name + KM always render
        + (k.tags.length > 0 ? 1 : 0)
        + (statCells.length > 0 ? 1 : 0)
        + (k.zonePct ? 1 : 0)
        + (hasContext ? 1 : 0)
    );
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
    y += (story ? 10 : 6) + (s.gapBonus ?? 0);
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
    const { ctx, k, box, story, draw, rarityCol } = s;
    const kmSize = story ? box.w * 0.135 : box.w * 0.12;
    const suffixSize = story ? 28 : 24;
    const gap = 16;
    y += kmSize * 0.92 + (s.gapBonus ?? 0);
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
        ctx.fillStyle = rarityCol;
        ctx.fillText(k.km, startX, y);
        ctx.letterSpacing = '0px';
        ctx.font = `700 ${suffixSize}px "JetBrains Mono"`;
        ctx.fillStyle = C.inkOnSky;
        ctx.fillText('KM', startX + kmW + gap, y);
    }
    return y;
}

/** A single tag + its measured pill width, for row-packing. */
interface PillSpec {
    label: string;
    w: number;
}

/** Measure each tag (with its emoji emblem) into a pill spec for row-packing. */
function measurePillSpecs(ctx: CanvasRenderingContext2D, tags: string[], tagEmojis: string[], padX: number): PillSpec[] {
    return tags.map((tag, i) => {
        const label = (tagEmojis[i] ?? '✦') + ' ' + tag;
        return { label, w: ctx.measureText(label).width + padX * 2 };
    });
}

/** Greedily wrap pills into rows that each fit within `maxWidth`, shared by the
 *  centred (hero) and left-aligned (route poster) badge layouts. */
function packPillRows(pills: PillSpec[], maxWidth: number, gap: number): PillSpec[][] {
    const rows: PillSpec[][] = [];
    let cur: PillSpec[] = [];
    let curW = 0;
    for (const p of pills) {
        if (cur.length > 0 && curW + gap + p.w > maxWidth) {
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
    return rows;
}

/** A centred row (wraps if needed) of up to 4 badge pills below the KM hero. */
function heroBadgeClusterRow(s: HeroBlock, y: number): number {
    const { ctx, k, box, story, draw } = s;
    const tags = k.tags.slice(0, 4);
    if (tags.length === 0) {
        return y;
    }
    ctx.font = `500 ${story ? 30 : 25}px "JetBrains Mono"`;
    const pillH = story ? 56 : 46;
    const gap = story ? 12 : 10;
    const padX = 22;
    const rows = packPillRows(measurePillSpecs(ctx, tags, k.tagEmojis, padX), box.w, gap);
    y += (story ? 32 : 24) + (s.gapBonus ?? 0);
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
    // Feed (1:1) keeps only one row (3 cells, same PACE/HR/CADENCE trio as
    // drawRute) instead of the full 2-row grid: the square canvas has far
    // less total height than story for the same width, so a second stat row
    // was squeezing the art window into a flattened ("gepeng") banner.
    const cells = story ? heroStatCells(k) : heroStatCells(k).slice(0, 3);
    if (cells.length === 0) {
        return y;
    }
    y += (story ? 30 : 22) + (s.gapBonus ?? 0);
    if (draw) {
        drawHeroStatGrid(ctx, cells, box.x, y, box.w, story);
    }
    return y + Math.ceil(cells.length / 3) * (story ? HERO_STAT_ROW_H.story : HERO_STAT_ROW_H.feed);
}

function heroZoneBarRow(s: HeroBlock, y: number): number {
    const { ctx, k, box, story, draw } = s;
    if (!k.zonePct) {
        return y;
    }
    const barH = story ? 34 : 24;
    y += (story ? 30 : 22) + (s.gapBonus ?? 0);
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
    const rows = packPillRows(measurePillSpecs(ctx, tags, k.tagEmojis, padX), w, gap);
    let by = y;
    rows.forEach((row) => {
        let x = left;
        row.forEach((p) => {
            drawBadgePill(ctx, p.label, x, by, p.w, pillH, padX);
            x += p.w + gap;
        });
        by += pillH + gap;
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
    y += (story ? 40 : 30) + (s.gapBonus ?? 0);
    if (draw) {
        // Same size + style in both formats — this row reads as small metadata
        // either way, no reason for feed to shrink it further than story.
        ctx.font = '500 29px "JetBrains Mono"';
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

/** Row height of the stat grid, shared by every caller that needs to know how
 *  much vertical space `drawHeroStatGrid` will actually consume. */
const HERO_STAT_ROW_H = { story: 92, feed: 74 } as const;

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
    const rowH = story ? HERO_STAT_ROW_H.story : HERO_STAT_ROW_H.feed;
    const labelSize = story ? 23 : 18;
    const valueSize = story ? 39 : 31;
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
 * Dark-frame TCG hero: a dark navy card with a single vivid rarity border, a
 * bright art window up top (big mascot watermark + route hero + floating
 * rarity/TRIMP/edition pills), and a dark stat block below (centred name, KM
 * hero, badges, stat grid, zone bar, location/wind/date context strip).
 * Mirrors the React Kartu component.
 */
function drawHero(d: DrawCtx): void {
    const { ctx, w, h, cfg, moodBunny } = d;
    const k = cfg.kartu;
    const story = cfg.format === 'story';
    const rarityCol = C.rarity[k.rarity] ?? C.line;
    const moodCol = moodSigilColor(k.mood);

    paintGlow(ctx, w / 2, h * 0.36, w * 0.5);
    drawCardFrame(ctx, w, h, rarityCol);

    const cx = 0;
    const cy = 0;
    const framePad = 12 + 24;

    // Single clean rarity border only — the live Kartu has no inner hairline or
    // corner pips, so neither does the share card.

    // Inner content frame.
    const innerX = cx + framePad;
    const innerW = w - framePad * 2;
    const innerTop = cy + framePad;
    const innerH = h - framePad * 2;
    const blockGap = 22;

    const makeBlock = (y: number, draw: boolean, gapBonus = 0): HeroBlock => ({
        ctx,
        k,
        box: { x: innerX, y, w: innerW, h: 0 },
        rarityCol,
        story,
        draw,
        gapBonus,
    });

    // Both formats: art window on top (route hero + mascot), stat block below.
    // Measure the block's natural height, cap the map height (maxArtFrac) so it
    // doesn't dominate, then spread any leftover space EVENLY across every section
    // (gapBonus) so the block fills the card with a consistent rhythm instead of
    // one big gap under a shorter map.
    const measuredBlockH = drawHeroBlock(makeBlock(innerTop, false)) + 20;
    const maxArtFrac = story ? 0.52 : 0.62;
    const artH = Math.min(Math.round(innerH * maxArtFrac), innerH - measuredBlockH - blockGap);
    const slack = Math.max(0, innerH - artH - blockGap - measuredBlockH);
    const gapBonus = slack / heroBlockSectionCount(k, story);
    drawHeroArtWindow(ctx, k, moodBunny, { x: innerX, y: innerTop, w: innerW, h: artH }, rarityCol, moodCol, story);
    drawHeroBlock(makeBlock(innerTop + artH + blockGap, true, gapBonus));
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
