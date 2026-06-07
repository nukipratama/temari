import { DAYBREAK } from '@/lib/chartTokens';
import { RARITY_HEX, RARITY_LABELS, RARITY_SYMBOL } from '@/lib/runcard';
import { streakLabel, weekRangeLabel, weeklyDeltaLabel } from '@/lib/weeklyRecap';
import type { Rarity } from '@/types/inertia';

/**
 * Deterministic, device-independent "Minggu Kamu" weekly-recap share renderer.
 *
 * Mirrors the language of the card share engine (lib/shareCard.ts): a fixed
 * internal resolution (1080x1920 story / 1080x1080 feed), dark-navy Daybreak
 * surface, Fraunces/Oswald/JetBrains-Mono type, and the TemanLari brand lockup,
 * so the live <canvas> preview IS the exported PNG with no DOM capture.
 */

export type RecapFormat = 'story' | 'feed';

/** Flat, render-ready recap data the canvas paints. Decoupled from the model. */
export interface RecapShareData {
    weekStart: string;
    weekEnd: string;
    kmLabel: string;
    runs: number;
    deltaPct: number | null;
    streakWeeks: number;
    bestCardMove: string | null;
    bestCardRarity: Rarity | null;
    nearestGoalTitle: string | null;
    nearestGoalRemainder: string | null;
}

const DIMS: Record<RecapFormat, { w: number; h: number }> = {
    story: { w: 1080, h: 1920 },
    feed: { w: 1080, h: 1080 },
};

const PAD = 92;

// Daybreak palette as literal hex (canvas can't read CSS vars), mirroring the
// shared bridge + the @theme block in app.css.
const C = {
    horizon: DAYBREAK.horizon,
    ink: DAYBREAK.ink,
    cream: '#f6f1e8',
    sky: DAYBREAK.sky,
    skyDeep: DAYBREAK.skyDeep,
    leaf: '#5a8a64',
    ember: '#d98a5c',
    inkOnSky: '#b8ad97',
    meta: 'rgba(246,241,232,0.72)',
    divider: 'rgba(246,241,232,0.18)',
};

let fontsReady: Promise<void> | null = null;

function ensureFonts(): Promise<void> {
    if (!fontsReady) {
        const specs = [
            'italic 600 120px "Fraunces"',
            'italic 500 120px "Fraunces"',
            '700 120px "JetBrains Mono"',
            '500 120px "JetBrains Mono"',
            '700 120px "Oswald"',
            '600 120px "Oswald"',
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

function paintBackground(ctx: CanvasRenderingContext2D, w: number, h: number): void {
    ctx.fillStyle = C.skyDeep;
    ctx.fillRect(0, 0, w, h);
    const g = ctx.createRadialGradient(w / 2, h * 0.3, 0, w / 2, h * 0.3, w * 0.6);
    g.addColorStop(0, 'rgba(232,160,118,0.22)');
    g.addColorStop(0.7, 'rgba(232,160,118,0)');
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, w, h);
}

// Flat canvas bunny, mirroring lib/shareCard's bunnySvg (cream tone for the dark
// surface). Kept local so the recap renderer has no cross-lib coupling.
function bunnySvg(): string {
    const face = C.cream;
    const blush = C.horizon;
    const features = C.ink;
    return `<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><defs><clipPath id="rb"><circle cx="50" cy="58" r="40"/></clipPath></defs><ellipse cx="32" cy="8" rx="9" ry="16" fill="${face}" transform="rotate(-12 32 8)"/><ellipse cx="68" cy="8" rx="9" ry="16" fill="${face}" transform="rotate(12 68 8)"/><ellipse cx="32" cy="10" rx="4" ry="9" fill="${blush}" transform="rotate(-12 32 10)"/><ellipse cx="68" cy="10" rx="4" ry="9" fill="${blush}" transform="rotate(12 68 10)"/><circle cx="50" cy="58" r="40" fill="${face}"/><g clip-path="url(#rb)"><rect x="10" y="40" width="80" height="14" fill="${blush}"/></g><circle cx="38" cy="68" r="4.5" fill="${features}"/><circle cx="62" cy="68" r="4.5" fill="${features}"/><path d="M 44 80 Q 50 85 56 80" fill="none" stroke="${features}" stroke-width="2.4" stroke-linecap="round"/></svg>`;
}

let bunnyImg: HTMLImageElement | null = null;

async function loadBunny(): Promise<HTMLImageElement | null> {
    if (bunnyImg) {
        return bunnyImg;
    }
    try {
        const img = await new Promise<HTMLImageElement>((resolve, reject) => {
            const i = new Image();
            i.onload = () => resolve(i);
            i.onerror = reject;
            i.src = `data:image/svg+xml;utf8,${encodeURIComponent(bunnySvg())}`;
        });
        bunnyImg = img;
        return img;
    } catch {
        return null;
    }
}

function drawBrand(ctx: CanvasRenderingContext2D, rightX: number, y: number, bunny: HTMLImageElement | null): void {
    const size = 52;
    const gap = 14;
    ctx.font = '700 38px "JetBrains Mono"';
    ctx.textBaseline = 'middle';
    ctx.textAlign = 'left';
    const word = 'TemanLari';
    const wordW = ctx.measureText(word).width;
    const startX = rightX - (size + gap + wordW);
    if (bunny) {
        ctx.drawImage(bunny, startX, y, size, size);
    }
    ctx.fillStyle = C.cream;
    ctx.fillText(word, startX + size + gap, y + size / 2 + 1);
}

/** Eyebrow pill: "MINGGU KAMU" on the horizon accent. Returns its height. */
function drawEyebrow(ctx: CanvasRenderingContext2D, x: number, y: number): number {
    const label = 'MINGGU KAMU';
    ctx.font = '700 30px "JetBrains Mono"';
    ctx.letterSpacing = '3px';
    const tw = ctx.measureText(label).width;
    const padX = 28;
    const h = 56;
    roundRectPath(ctx, x, y, tw + padX * 2, h, h / 2);
    ctx.fillStyle = C.horizon;
    ctx.fill();
    ctx.fillStyle = C.skyDeep;
    ctx.textBaseline = 'middle';
    ctx.textAlign = 'left';
    ctx.fillText(label, x + padX, y + h / 2 + 1);
    ctx.letterSpacing = '0px';
    return h;
}

/** A single label-over-value chip stacked vertically. */
function drawStatRow(
    ctx: CanvasRenderingContext2D,
    x: number,
    y: number,
    label: string,
    value: string,
    valueColor: string,
): void {
    ctx.textAlign = 'left';
    ctx.textBaseline = 'alphabetic';
    ctx.font = '700 26px "JetBrains Mono"';
    ctx.letterSpacing = '2px';
    ctx.fillStyle = C.inkOnSky;
    ctx.fillText(label, x, y);
    ctx.letterSpacing = '0px';
    ctx.font = 'italic 600 46px "Fraunces"';
    ctx.fillStyle = valueColor;
    ctx.fillText(value, x, y + 56);
}

/**
 * Paint the recap onto `canvas` at its fixed internal resolution. Idempotent:
 * safe to call on every config change to refresh the preview.
 */
export async function drawRecapShare(
    canvas: HTMLCanvasElement,
    recap: RecapShareData,
    format: RecapFormat,
): Promise<void> {
    const { w, h } = DIMS[format];
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');
    if (!ctx) {
        return;
    }

    await ensureFonts();
    const bunny = await loadBunny();

    ctx.clearRect(0, 0, w, h);
    paintBackground(ctx, w, h);
    paintRecap(ctx, w, recap, format, bunny);
}

function paintRecap(
    ctx: CanvasRenderingContext2D,
    w: number,
    recap: RecapShareData,
    format: RecapFormat,
    bunny: HTMLImageElement | null,
): void {
    const story = format === 'story';
    drawBrand(ctx, w - PAD, PAD, bunny);

    let y = PAD + 4;
    y += drawEyebrow(ctx, PAD, y) + (story ? 56 : 40);

    // Week range.
    ctx.font = '500 32px "JetBrains Mono"';
    ctx.fillStyle = C.meta;
    ctx.textAlign = 'left';
    ctx.textBaseline = 'alphabetic';
    ctx.letterSpacing = '2px';
    ctx.fillText(weekRangeLabel(recap.weekStart, recap.weekEnd).toUpperCase(), PAD, y);
    ctx.letterSpacing = '0px';

    // KM hero number.
    y += story ? 240 : 200;
    const kmSize = story ? 300 : 240;
    ctx.font = `700 ${kmSize}px "Oswald"`;
    ctx.fillStyle = C.horizon;
    ctx.letterSpacing = '-2px';
    ctx.fillText(recap.kmLabel, PAD, y);
    const kmW = ctx.measureText(recap.kmLabel).width;
    ctx.letterSpacing = '0px';
    ctx.font = '700 48px "JetBrains Mono"';
    ctx.fillStyle = C.inkOnSky;
    ctx.fillText('KM', PAD + kmW + 24, y);

    // Delta line + runs.
    y += story ? 80 : 64;
    ctx.font = 'italic 500 44px "Fraunces"';
    ctx.fillStyle = deltaColor(recap.deltaPct);
    ctx.fillText(weeklyDeltaLabel(recap.deltaPct), PAD, y);
    y += story ? 64 : 56;
    ctx.font = '500 34px "JetBrains Mono"';
    ctx.fillStyle = C.meta;
    ctx.fillText(`${recap.runs} lari minggu ini`, PAD, y);

    // Divider.
    y += story ? 80 : 64;
    ctx.strokeStyle = C.divider;
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.moveTo(PAD, y);
    ctx.lineTo(w - PAD, y);
    ctx.stroke();

    // Streak + best card as two stat rows.
    y += story ? 80 : 64;
    const streak = streakLabel(recap.streakWeeks);
    drawStatRow(ctx, PAD, y, 'STREAK', streak ?? 'mulai minggu ini', streak ? C.leaf : C.inkOnSky);

    if (recap.bestCardMove && recap.bestCardRarity) {
        const rarityCol = RARITY_HEX[recap.bestCardRarity];
        const rarityLabel = `${RARITY_SYMBOL[recap.bestCardRarity]} ${RARITY_LABELS[recap.bestCardRarity]}`;
        drawStatRow(ctx, w / 2 + 20, y, `KARTU TERBAIK · ${rarityLabel.toUpperCase()}`, recap.bestCardMove, rarityCol);
    }

    // Nearest goal nudge.
    if (recap.nearestGoalTitle && recap.nearestGoalRemainder) {
        y += story ? 130 : 110;
        ctx.font = '700 26px "JetBrains Mono"';
        ctx.letterSpacing = '2px';
        ctx.fillStyle = C.inkOnSky;
        ctx.fillText('TARGET TERDEKAT', PAD, y);
        ctx.letterSpacing = '0px';
        y += 52;
        ctx.font = 'italic 600 42px "Fraunces"';
        ctx.fillStyle = C.cream;
        ctx.fillText(`${recap.nearestGoalRemainder} ke ${recap.nearestGoalTitle}`, PAD, y);
    }
}

function deltaColor(deltaPct: number | null): string {
    if (deltaPct === null || deltaPct === 0) {
        return C.inkOnSky;
    }
    return deltaPct > 0 ? C.leaf : C.ember;
}

/** Render the recap and return it as a PNG blob (full internal resolution). */
export async function recapShareBlob(recap: RecapShareData, format: RecapFormat): Promise<Blob> {
    const canvas = document.createElement('canvas');
    await drawRecapShare(canvas, recap, format);
    return new Promise((resolve, reject) => {
        canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('toBlob failed'))), 'image/png');
    });
}
