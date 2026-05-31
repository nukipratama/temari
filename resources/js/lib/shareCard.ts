import { DAYBREAK } from '@/lib/chartTokens';
import { projectPolyline } from '@/lib/route';
import { RARITY_LABELS } from '@/lib/runcard';
import type { CardEdition, Rarity } from '@/types/inertia';

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
    rarity: Rarity;
    subtitle: string | null;
    date: string | null;
    km: string;
    durasi: string;
    pace: string | null;
    trimp: string;
    hr: string | null;
    location: string | null;
    weather: string | null;
    tags: string[];
    quote: string | null;
    /** Encoded route polyline for the card / route templates (optional). */
    polyline?: string | null;
    /** Collector number within the rarity (optional). */
    edition?: CardEdition | null;
}

export type Theme = 'Dawn' | 'Sky' | 'Cream' | 'Inverted';
export type Format = 'story' | 'feed';
export type Layout = 'kartu' | 'pack' | 'rute' | 'polaroid' | 'poster' | 'struk';

export interface ShareCardConfig {
    kartu: ShareKartuData;
    theme: Theme;
    layout: Layout;
    format: Format;
    showStats: boolean;
    showQuote: boolean;
}

const DIMS: Record<Format, { w: number; h: number }> = {
    story: { w: 1080, h: 1920 },
    feed: { w: 1080, h: 1080 },
};

const PAD = 92;

// Card tile aspect (height / width) — shared by every template that draws a tile.
const TILE_ASPECT = 1.34;

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
    rarity: {
        common: DAYBREAK.stone,
        uncommon: DAYBREAK.leaf,
        rare: DAYBREAK.mumet,
        epic: DAYBREAK.citrus,
        legendary: DAYBREAK.ember,
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

function palette(theme: Theme): Palette {
    const isDark = theme !== 'Cream';
    return {
        isDark,
        text: isDark ? C.cream : C.ink,
        name: isDark ? C.horizon : C.ink,
        meta: isDark ? 'rgba(246,241,232,0.72)' : C.ink3,
        divider: isDark ? 'rgba(246,241,232,0.18)' : 'rgba(31,39,71,0.10)',
        quote: isDark ? 'rgba(246,241,232,0.88)' : C.ink2,
    };
}

function paintBackground(ctx: CanvasRenderingContext2D, theme: Theme, w: number, h: number): void {
    if (theme === 'Cream') {
        ctx.fillStyle = C.creamDeep;
    } else if (theme === 'Sky') {
        ctx.fillStyle = C.sky;
    } else if (theme === 'Inverted') {
        ctx.fillStyle = C.skyDeep;
    } else {
        const g = ctx.createLinearGradient(w * 0.18, 0, w * 0.82, h);
        g.addColorStop(0, C.skyDeep);
        g.addColorStop(0.5, C.sky);
        g.addColorStop(0.88, '#95573c');
        g.addColorStop(1, C.horizonDeep);
        ctx.fillStyle = g;
    }
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

/**
 * Stroke a route polyline inside a box (x, y, w, h) using the shared
 * `projectPolyline` geometry (same normalization as the on-card RouteGlyph).
 * Returns false (drew nothing) when there's no drawable route, so callers fall back.
 */
function drawRoute(
    ctx: CanvasRenderingContext2D,
    polyline: string | null | undefined,
    box: { x: number; y: number; w: number; h: number },
    stroke: string,
    lineWidth: number,
): boolean {
    const projected = projectPolyline(polyline, box.w, box.h, lineWidth * 1.5, 240);
    if (projected === null) {
        return false;
    }

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
    ctx.lineWidth = lineWidth;
    ctx.lineJoin = 'round';
    ctx.lineCap = 'round';
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
function bunnySvg(tone: 'ink' | 'cream'): string {
    const isInk = tone === 'ink';
    const face = isInk ? C.ink : C.cream;
    const blush = isInk ? C.horizon : C.horizonDeep;
    const features = isInk ? C.cream : C.ink;
    return `<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><defs><clipPath id="b"><circle cx="50" cy="58" r="40"/></clipPath></defs><ellipse cx="32" cy="8" rx="9" ry="16" fill="${face}" transform="rotate(-12 32 8)"/><ellipse cx="68" cy="8" rx="9" ry="16" fill="${face}" transform="rotate(12 68 8)"/><ellipse cx="32" cy="10" rx="4" ry="9" fill="${blush}" transform="rotate(-12 32 10)"/><ellipse cx="68" cy="10" rx="4" ry="9" fill="${blush}" transform="rotate(12 68 10)"/><circle cx="50" cy="58" r="40" fill="${face}"/><g clip-path="url(#b)"><rect x="10" y="40" width="80" height="14" fill="${C.horizon}"/></g><circle cx="38" cy="68" r="4.5" fill="${features}"/><circle cx="62" cy="68" r="4.5" fill="${features}"/><path d="M 44 80 Q 50 85 56 80" fill="none" stroke="${features}" stroke-width="2.4" stroke-linecap="round"/></svg>`;
}

function loadImage(src: string): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = reject;
        img.src = src;
    });
}

// Only two tones exist and the glyph never changes; cache each decoded image
// so repeated repaints reuse it instead of re-encoding and re-decoding the SVG.
const bunnyCache: Partial<Record<'ink' | 'cream', HTMLImageElement>> = {};

async function loadBunny(tone: 'ink' | 'cream'): Promise<HTMLImageElement | null> {
    if (bunnyCache[tone]) {
        return bunnyCache[tone];
    }
    try {
        const img = await loadImage(`data:image/svg+xml;utf8,${encodeURIComponent(bunnySvg(tone))}`);
        bunnyCache[tone] = img;
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
    statItems: Array<{ v: string; l: string }>;
}

/** Vertical label/value stat rows. Returns total height drawn. */
function drawStatRows(d: DrawCtx, x: number, y: number, maxW: number, items: Array<{ v: string; l: string }>): number {
    const { ctx, pal } = d;
    const rowH = 70;
    items.forEach((item, i) => {
        const ry = y + i * rowH + rowH / 2;
        ctx.textBaseline = 'middle';
        ctx.font = '700 30px "JetBrains Mono"';
        ctx.letterSpacing = '2px';
        ctx.fillStyle = pal.meta;
        ctx.textAlign = 'left';
        ctx.fillText(item.l.toUpperCase(), x, ry);
        ctx.letterSpacing = '0px';
        ctx.font = '700 40px "Plus Jakarta Sans"';
        ctx.fillStyle = pal.text;
        ctx.textAlign = 'right';
        ctx.fillText(item.v, x + maxW, ry);
    });
    return items.length * rowH;
}

function drawTextBlock(
    ctx: CanvasRenderingContext2D,
    lines: string[],
    x: number,
    y: number,
    lineHeight: number,
    color: string,
    align: CanvasTextAlign = 'left',
): number {
    ctx.textAlign = align;
    ctx.textBaseline = 'alphabetic';
    ctx.fillStyle = color;
    lines.forEach((line, i) => ctx.fillText(line, x, y + i * lineHeight));
    return lines.length * lineHeight;
}

/** Bottom-left mono date stamp, shared by the poster and angka templates. */
function drawDateFooter(d: DrawCtx): void {
    const { ctx, h, cfg, pal } = d;
    if (!cfg.kartu.date) {
        return;
    }
    ctx.font = '500 30px "JetBrains Mono"';
    ctx.letterSpacing = '2px';
    ctx.fillStyle = pal.meta;
    ctx.textAlign = 'left';
    ctx.textBaseline = 'alphabetic';
    ctx.fillText(cfg.kartu.date.replace('\n', ' · '), PAD, h - PAD);
    ctx.letterSpacing = '0px';
}

function drawPoster(d: DrawCtx): void {
    const { ctx, w, h, cfg, pal, bunny, statItems } = d;
    paintGlow(ctx, w / 2, h * 0.44, w * 0.5);
    drawBrand(ctx, w - PAD, PAD, pal.isDark, bunny);
    drawRarityFlag(ctx, PAD, PAD, cfg.kartu.rarity);

    // Footer (stat list + date), measured up from the bottom.
    const items = cfg.format === 'story' ? statItems.slice(0, 4) : statItems.slice(0, 3);
    const showStats = cfg.showStats && items.length > 0;
    const statsH = showStats ? items.length * 70 + 30 : 0;
    const dateH = cfg.kartu.date ? 56 : 0;
    const footerTop = h - PAD - statsH - dateH;

    if (showStats) {
        ctx.strokeStyle = pal.divider;
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(PAD, footerTop);
        ctx.lineTo(w - PAD, footerTop);
        ctx.stroke();
        drawStatRows(d, PAD, footerTop + 30, w - PAD * 2, items);
    }
    drawDateFooter(d);

    // Hero (name + quote), optically centered between header and footer.
    const heroTop = PAD + 70;
    const heroBottom = footerTop - 40;
    const nameSize = cfg.format === 'story' ? 104 : 84;
    ctx.font = `italic 600 ${nameSize}px "Fraunces"`;
    const nameLines = wrapText(ctx, `${cfg.kartu.name}.`, w - PAD * 2);
    const nameLH = nameSize * 0.98;
    const quote = cfg.format === 'story' && cfg.showQuote ? cfg.kartu.quote : null;
    let quoteLines: string[] = [];
    if (quote) {
        ctx.font = 'italic 500 42px "Fraunces"';
        quoteLines = wrapText(ctx, quote, w - PAD * 2 - 28);
    }
    const blockH = nameLines.length * nameLH + (quoteLines.length ? 36 + quoteLines.length * 54 : 0);
    let cursorY = heroTop + (heroBottom - heroTop - blockH) / 2 + nameSize * 0.74;

    ctx.font = `italic 600 ${nameSize}px "Fraunces"`;
    cursorY += drawTextBlock(ctx, nameLines, PAD, cursorY, nameLH, pal.name);
    if (quoteLines.length) {
        const qy = cursorY + 36;
        ctx.strokeStyle = C.horizon;
        ctx.lineWidth = 5;
        ctx.beginPath();
        ctx.moveTo(PAD + 2, qy - 38);
        ctx.lineTo(PAD + 2, qy - 38 + quoteLines.length * 54);
        ctx.stroke();
        ctx.font = 'italic 500 42px "Fraunces"';
        drawTextBlock(ctx, quoteLines, PAD + 28, qy, 54, pal.quote);
    }
}

/** Route-map hero: the run's route as big poster art with name + KM + edition. */
function drawRute(d: DrawCtx): void {
    const { ctx, w, h, cfg, pal, bunny } = d;
    const k = cfg.kartu;
    paintGlow(ctx, w / 2, h * 0.38, w * 0.5);
    drawBrand(ctx, w - PAD, PAD, pal.isDark, bunny);
    drawRarityFlag(ctx, PAD, PAD, k.rarity);

    const box = { x: PAD, y: PAD + 110, w: w - PAD * 2, h: h * (cfg.format === 'story' ? 0.42 : 0.4) };
    drawRoute(ctx, k.polyline, box, pal.isDark ? C.horizon : C.horizonDeep, 12);

    let y = box.y + box.h + 90;
    ctx.textAlign = 'left';
    ctx.textBaseline = 'alphabetic';
    ctx.font = `italic 600 ${cfg.format === 'story' ? 88 : 72}px "Fraunces"`;
    ctx.fillStyle = pal.name;
    wrapText(ctx, `${k.name}.`, w - PAD * 2)
        .slice(0, 2)
        .forEach((ln) => {
            ctx.fillText(ln, PAD, y);
            y += cfg.format === 'story' ? 92 : 78;
        });

    y += 24;
    const kmSize = cfg.format === 'story' ? 200 : 150;
    ctx.font = `700 ${kmSize}px "Oswald"`;
    ctx.fillStyle = pal.name;
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

    drawDateFooter(d);
}

/** Polaroid: the card tile in an instant-photo frame with a handwritten caption. */
function drawPolaroid(d: DrawCtx): void {
    const { ctx, w, h, cfg } = d;
    const k = cfg.kartu;
    const tileW = cfg.format === 'story' ? w * 0.62 : w * 0.56;
    const tileH = tileW * TILE_ASPECT;
    const frameW = tileW + 88;
    const frameX = (w - frameW) / 2;
    const photoTop = cfg.format === 'story' ? h * 0.12 : PAD * 0.7;

    ctx.save();
    ctx.shadowColor = 'rgba(31,39,71,0.3)';
    ctx.shadowBlur = 48;
    ctx.shadowOffsetY = 28;
    roundRectPath(ctx, frameX, photoTop, frameW, tileH + 210, 14);
    ctx.fillStyle = C.cream;
    ctx.fill();
    ctx.restore();

    drawKartuTile(d, w / 2, photoTop + 44 + tileH / 2, tileW);

    const caption = k.quote ?? k.subtitle ?? `${k.km} km bareng Temari`;
    ctx.font = 'italic 500 46px "Fraunces"';
    ctx.fillStyle = C.ink2;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'alphabetic';
    const capY = photoTop + 44 + tileH + 96;
    wrapText(ctx, caption, frameW - 60)
        .slice(0, 2)
        .forEach((ln, i) => ctx.fillText(ln, w / 2, capY + i * 52));
}

/** Booster-pack reveal: the card sliding out of a torn rarity-foil wrapper. */
function drawPack(d: DrawCtx): void {
    const { ctx, w, h, cfg, pal, bunny } = d;
    const k = cfg.kartu;
    paintGlow(ctx, w / 2, h * 0.46, w * 0.5);
    drawBrand(ctx, w - PAD, PAD, pal.isDark, bunny);

    ctx.font = '700 30px "JetBrains Mono"';
    ctx.letterSpacing = '3px';
    ctx.fillStyle = C.horizon;
    ctx.textAlign = 'left';
    ctx.textBaseline = 'middle';
    ctx.fillText('★ BARU NARIK', PAD, PAD + 28);
    ctx.letterSpacing = '0px';

    // Foil pack (lower portion) with a torn zig-zag top edge.
    const packTop = h * 0.54;
    const zig = 30;
    ctx.beginPath();
    ctx.moveTo(PAD, packTop);
    for (let zx = PAD, i = 0; zx < w - PAD; zx += zig * 2, i += 1) {
        ctx.lineTo(zx + zig, packTop - (i % 2 === 0 ? 24 : 0));
        ctx.lineTo(zx + zig * 2, packTop);
    }
    ctx.lineTo(w - PAD, h - PAD);
    ctx.lineTo(PAD, h - PAD);
    ctx.closePath();
    ctx.fillStyle = C.rarity[k.rarity] ?? C.line;
    ctx.fill();

    ctx.font = '700 40px "JetBrains Mono"';
    ctx.letterSpacing = '5px';
    ctx.fillStyle = k.rarity === 'epic' || k.rarity === 'legendary' ? C.ink : C.cream;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText('TEMANLARI', w / 2, h - PAD - 40);
    ctx.letterSpacing = '0px';

    // Card sliding out above the torn edge.
    const tileW = cfg.format === 'story' ? w * 0.52 : w * 0.48;
    drawKartuTile(d, w / 2, packTop - tileW * 0.52, tileW);
}

function drawKartuTile(d: DrawCtx, cx: number, cy: number, tileW: number): void {
    const { ctx, cfg } = d;
    const k = cfg.kartu;
    const tileH = tileW * TILE_ASPECT;
    const x = cx - tileW / 2;
    const y = cy - tileH / 2;
    const pad = tileW * 0.075;
    const rarityCol = C.rarity[k.rarity] ?? C.line;

    ctx.save();
    ctx.translate(cx, cy);
    ctx.rotate(-0.035);
    ctx.translate(-cx, -cy);

    // Card body + rarity frame.
    ctx.shadowColor = 'rgba(31,39,71,0.35)';
    ctx.shadowBlur = 40;
    ctx.shadowOffsetY = 24;
    roundRectPath(ctx, x, y, tileW, tileH, 28);
    ctx.fillStyle = C.surfaceCard;
    ctx.fill();
    ctx.shadowColor = 'transparent';
    ctx.shadowBlur = 0;
    ctx.shadowOffsetY = 0;
    ctx.lineWidth = 5;
    ctx.strokeStyle = rarityCol;
    roundRectPath(ctx, x + 2.5, y + 2.5, tileW - 5, tileH - 5, 26);
    ctx.stroke();

    let cursor = y + pad;

    // Chrome row: rarity ribbon (left) + edition (right).
    ctx.font = '700 26px "JetBrains Mono"';
    ctx.letterSpacing = '2px';
    const rlabel = (RARITY_LABELS[k.rarity as keyof typeof RARITY_LABELS] ?? k.rarity).toUpperCase();
    const ribbonW = ctx.measureText(rlabel).width + 44;
    roundRectPath(ctx, x + pad, cursor, ribbonW, 46, 23);
    ctx.fillStyle = rarityCol;
    ctx.fill();
    ctx.fillStyle = k.rarity === 'epic' || k.rarity === 'legendary' ? C.ink : C.cream;
    ctx.textAlign = 'left';
    ctx.textBaseline = 'middle';
    ctx.fillText(rlabel, x + pad + 22, cursor + 24);
    ctx.letterSpacing = '0px';
    if (k.edition) {
        ctx.font = '600 32px "Oswald"';
        ctx.fillStyle = C.ink3;
        ctx.textAlign = 'right';
        ctx.fillText(`#${k.edition.index}/${k.edition.total}`, x + tileW - pad, cursor + 24);
    }
    cursor += 46 + pad * 0.5;

    // Nameplate (Oswald, uppercase, up to 2 lines).
    const nameSize = tileW * 0.108;
    ctx.font = `700 ${nameSize}px "Oswald"`;
    ctx.fillStyle = C.ink;
    ctx.textAlign = 'left';
    ctx.textBaseline = 'alphabetic';
    wrapText(ctx, k.name.toUpperCase(), tileW - pad * 2)
        .slice(0, 2)
        .forEach((ln) => {
            cursor += nameSize;
            ctx.fillText(ln, x + pad, cursor);
            cursor += nameSize * 0.08;
        });
    cursor += pad * 0.5;

    // Art window: the run's route, rarity-tinted.
    const artH = tileH * 0.3;
    const art = { x: x + pad, y: cursor, w: tileW - pad * 2, h: artH };
    roundRectPath(ctx, art.x, art.y, art.w, art.h, 16);
    ctx.fillStyle = C.surfaceSunken;
    ctx.fill();
    ctx.lineWidth = 2;
    ctx.strokeStyle = C.line;
    roundRectPath(ctx, art.x, art.y, art.w, art.h, 16);
    ctx.stroke();
    ctx.save();
    roundRectPath(ctx, art.x, art.y, art.w, art.h, 16);
    ctx.clip();
    drawRoute(ctx, k.polyline, art, rarityCol, 6);
    ctx.restore();
    cursor = art.y + artH + pad * 0.7;

    // Hero KM (Oswald), with duration/TRIMP demoted to its own line BELOW it
    // (never on the same baseline — that overlapped the number).
    const kmSize = tileW * 0.155;
    ctx.font = `700 ${kmSize}px "Oswald"`;
    ctx.fillStyle = C.horizonDeep;
    ctx.textAlign = 'left';
    ctx.textBaseline = 'alphabetic';
    const kmBaseline = cursor + kmSize * 0.82;
    ctx.fillText(k.km, x + pad, kmBaseline);
    const kmW = ctx.measureText(k.km).width;
    ctx.font = '700 22px "JetBrains Mono"';
    ctx.letterSpacing = '2px';
    ctx.fillStyle = C.ink3;
    ctx.fillText('KM', x + pad + kmW + 14, kmBaseline);
    ctx.letterSpacing = '0px';
    ctx.font = '500 24px "JetBrains Mono"';
    ctx.fillStyle = C.ink3;
    ctx.textAlign = 'left';
    ctx.fillText(`${k.durasi}  ·  TRIMP ${k.trimp}`, x + pad, kmBaseline + 40);

    ctx.restore();
}

function drawKartu(d: DrawCtx): void {
    const { ctx, w, h, cfg, pal, bunny } = d;
    paintGlow(ctx, w / 2, h * 0.46, w * 0.5);
    drawBrand(ctx, w - PAD, PAD, pal.isDark, bunny);

    ctx.font = '700 30px "JetBrains Mono"';
    ctx.letterSpacing = '3px';
    ctx.fillStyle = C.horizon;
    ctx.textAlign = 'left';
    ctx.textBaseline = 'middle';
    ctx.fillText('★ KARTU KAMU', PAD, PAD + 28);
    ctx.letterSpacing = '0px';

    const story = cfg.format === 'story';
    const quote = story && cfg.showQuote ? cfg.kartu.quote : null;
    // Bigger tile, positioned so the tile + quote read as one centered block
    // (no large dead void below the card).
    const tileW = story ? w * 0.72 : w * 0.58;
    const tileH = tileW * TILE_ASPECT;
    const top = story ? Math.max(PAD + 140, (h - tileH - 150) / 2) : (h - tileH) / 2 + 20;
    drawKartuTile(d, w / 2, top + tileH / 2, tileW);

    if (quote) {
        ctx.font = 'italic 500 40px "Fraunces"';
        const lines = wrapText(ctx, `“${quote}”`, w - PAD * 2);
        ctx.textAlign = 'center';
        ctx.fillStyle = pal.quote;
        ctx.textBaseline = 'alphabetic';
        const qy = top + tileH + 78;
        lines.forEach((ln, i) => ctx.fillText(ln, w / 2, qy + i * 52));
    }
}

function drawStruk(d: DrawCtx): void {
    const { ctx, w, h, cfg, pal, statItems } = d;
    let y = PAD;
    const dash = (): void => {
        ctx.strokeStyle = pal.divider;
        ctx.lineWidth = 3;
        ctx.setLineDash([10, 10]);
        ctx.beginPath();
        ctx.moveTo(PAD, y);
        ctx.lineTo(w - PAD, y);
        ctx.stroke();
        ctx.setLineDash([]);
        y += 36;
    };

    ctx.textAlign = 'center';
    ctx.textBaseline = 'alphabetic';
    ctx.font = '500 30px "JetBrains Mono"';
    ctx.letterSpacing = '6px';
    ctx.fillStyle = pal.meta;
    ctx.fillText('TEMANLARI', w / 2, y + 28);
    ctx.letterSpacing = '0px';
    y += 60;
    dash();

    ctx.textAlign = 'left';
    ctx.font = '700 30px "JetBrains Mono"';
    ctx.letterSpacing = '3px';
    ctx.fillStyle = C.horizon;
    ctx.fillText(`★ ${(RARITY_LABELS[cfg.kartu.rarity as keyof typeof RARITY_LABELS] ?? cfg.kartu.rarity).toUpperCase()}`, PAD, y + 26);
    ctx.letterSpacing = '0px';
    y += 56;
    ctx.font = 'italic 600 64px "Fraunces"';
    ctx.fillStyle = pal.name;
    const nameLines = wrapText(ctx, `${cfg.kartu.name}.`, w - PAD * 2);
    nameLines.forEach((ln) => {
        ctx.fillText(ln, PAD, y + 56);
        y += 70;
    });
    y += 16;
    dash();

    if (cfg.showStats) {
        const items = cfg.format === 'story' ? statItems : statItems.slice(0, 3);
        ctx.font = '700 34px "JetBrains Mono"';
        items.forEach((item) => {
            ctx.textAlign = 'left';
            ctx.letterSpacing = '1px';
            ctx.fillStyle = pal.meta;
            ctx.fillText(item.l.toUpperCase(), PAD, y + 30);
            ctx.letterSpacing = '0px';
            ctx.textAlign = 'right';
            ctx.fillStyle = pal.text;
            ctx.fillText(item.v, w - PAD, y + 30);
            y += 56;
        });
        y += 12;
    }

    const quote = cfg.format === 'story' && cfg.showQuote ? cfg.kartu.quote : null;
    if (quote) {
        dash();
        ctx.font = 'italic 500 38px "Fraunces"';
        ctx.fillStyle = pal.quote;
        ctx.textAlign = 'left';
        const lines = wrapText(ctx, `“${quote}”`, w - PAD * 2);
        lines.forEach((ln) => {
            ctx.fillText(ln, PAD, y + 38);
            y += 50;
        });
    }

    // Footer pinned to the bottom.
    y = h - PAD - 36;
    dash();
    ctx.font = '500 30px "JetBrains Mono"';
    ctx.fillStyle = pal.meta;
    ctx.textAlign = 'left';
    ctx.fillText(cfg.kartu.date ? cfg.kartu.date.replace('\n', ' · ') : 'TemanLari', PAD, y + 24);
    ctx.textAlign = 'right';
    ctx.fillText('teman-lari', w - PAD, y + 24);
}

const TEMPLATES: Record<Layout, (d: DrawCtx) => void> = {
    kartu: drawKartu,
    pack: drawPack,
    rute: drawRute,
    polaroid: drawPolaroid,
    poster: drawPoster,
    struk: drawStruk,
};

function statItemsFor(kartu: ShareKartuData): Array<{ v: string; l: string }> {
    return [
        { v: kartu.km, l: 'KM' },
        { v: kartu.durasi, l: 'Durasi' },
        ...(kartu.pace ? [{ v: `${kartu.pace}/km`, l: 'Pace' }] : []),
        { v: String(kartu.trimp), l: 'TRIMP' },
        ...(kartu.hr ? [{ v: kartu.hr, l: 'HR' }] : []),
        ...(kartu.weather ? [{ v: kartu.weather, l: 'Cuaca' }] : []),
        ...(kartu.location ? [{ v: kartu.location.split(',')[0].trim(), l: 'Lokasi' }] : []),
    ];
}

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
    const pal = palette(cfg.theme);
    const bunny = await loadBunny(pal.isDark ? 'cream' : 'ink');

    ctx.clearRect(0, 0, w, h);
    paintBackground(ctx, cfg.theme, w, h);

    const d: DrawCtx = { ctx, w, h, cfg, pal, bunny, statItems: statItemsFor(cfg.kartu) };
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
