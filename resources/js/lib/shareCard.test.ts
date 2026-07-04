import polylineCodec from '@mapbox/polyline';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { drawShareCard, shareCardBlob, type Layout, type Format, type ShareKartuData } from './shareCard';

// A straight two-point line: start and finish project to opposite corners of
// the route box, so `drawRoute`'s point-to-point gate is unambiguously true.
const pointToPointPolyline = polylineCodec.encode([[0, 0], [0.01, 0.01]]);
// A loop that returns to its exact starting coordinate, so start === finish
// and the point-to-point gate is unambiguously false.
const loopPolyline = polylineCodec.encode([[0, 0], [0, 0.01], [0.01, 0.01], [0, 0]]);

const kartu: ShareKartuData = {
    id: 1,
    name: 'Pemburu Sabar',
    shareUrl: 'https://teman-lari.test/k/1?signature=abc',
    rarity: 'legendary',
    mood: 'nyala',
    subtitle: null,
    date: '30 Mei 2026\n06:30',
    km: '42.61',
    durasi: '6 jam 8 menit',
    pace: '5:48',
    trimp: '913',
    hr: '164 bpm',
    cadence: '178 spm',
    fastestKm: '5:12/km',
    zonePct: { Z1: 10, Z2: 40, Z3: 30, Z4: 15, Z5: 5 },
    location: 'Gelora Bung Karno, Jakarta',
    weather: '27°C',
    tags: ['Anak Pagi'],
    tagEmojis: ['🌅'],
    quote: 'Kartu ini lahir dari sesi yang tenang tapi solid.',
    polyline: '_p~iF~ps|U_ulLnnqC_mqNvxq`@',
    edition: { index: 2, total: 7 },
};

function makeCtx() {
    const gradient = { addColorStop: vi.fn() };
    return {
        clearRect: vi.fn(),
        fillRect: vi.fn(),
        fillText: vi.fn(),
        measureText: vi.fn(() => ({ width: 120 })),
        createLinearGradient: vi.fn(() => gradient),
        createRadialGradient: vi.fn(() => gradient),
        beginPath: vi.fn(),
        closePath: vi.fn(),
        moveTo: vi.fn(),
        lineTo: vi.fn(),
        arc: vi.fn(),
        arcTo: vi.fn(),
        fill: vi.fn(),
        stroke: vi.fn(),
        clip: vi.fn(),
        save: vi.fn(),
        restore: vi.fn(),
        translate: vi.fn(),
        rotate: vi.fn(),
        drawImage: vi.fn(),
        setLineDash: vi.fn(),
        fillStyle: '',
        strokeStyle: '',
        lineWidth: 0,
        lineCap: '',
        lineJoin: '',
        globalAlpha: 1,
        font: '',
        textAlign: '',
        textBaseline: '',
        letterSpacing: '',
        shadowColor: '',
        shadowBlur: 0,
        shadowOffsetY: 0,
    };
}

/** Like `makeCtx`, but records every `lineWidth` assignment so a test can
 *  inspect the sequence of stroke widths a draw pass actually used. */
function makeCtxWithLineWidthLog() {
    const ctx = makeCtx();
    const widths: number[] = [];
    let current = ctx.lineWidth;
    Object.defineProperty(ctx, 'lineWidth', {
        get: () => current,
        set: (v: number) => {
            current = v;
            widths.push(v);
        },
    });
    return { ctx, widths };
}

beforeEach(() => {
    Object.defineProperty(document, 'fonts', {
        value: { load: vi.fn(() => Promise.resolve()), ready: Promise.resolve() },
        configurable: true,
    });
    // jsdom never fires image load; resolve immediately so loadBunny settles.
    class FakeImage {
        onload: (() => void) | null = null;
        onerror: (() => void) | null = null;
        set src(_v: string) {
            queueMicrotask(() => this.onload?.());
        }
    }
    (globalThis as unknown as { Image: unknown }).Image = FakeImage;
});

describe('drawShareCard', () => {
    const layouts: Layout[] = ['kartu', 'rute'];
    const formats: Format[] = ['story', 'feed'];

    it.each(layouts)('paints the %s layout at the fixed story resolution', async (layout) => {
        const ctx = makeCtx();
        const canvas = { width: 0, height: 0, getContext: () => ctx } as unknown as HTMLCanvasElement;
        await drawShareCard(canvas, { kartu, layout, format: 'story' });
        expect(canvas.width).toBe(1080);
        expect(canvas.height).toBe(1920);
        expect(ctx.fillRect).toHaveBeenCalled(); // background painted
        expect(ctx.fillText).toHaveBeenCalled(); // text drawn
    });

    it.each(formats)('uses the right canvas size for %s', async (format) => {
        const ctx = makeCtx();
        const canvas = { width: 0, height: 0, getContext: () => ctx } as unknown as HTMLCanvasElement;
        await drawShareCard(canvas, { kartu, layout: 'rute', format });
        expect(canvas.height).toBe(format === 'story' ? 1920 : 1080);
    });

    const rarities: ShareKartuData['rarity'][] = ['common', 'uncommon', 'rare', 'epic', 'legendary'];

    it.each(rarities)('renders the redesigned kartu hero for %s rarity (route + mascot)', async (rarity) => {
        const ctx = makeCtx();
        const canvas = { width: 0, height: 0, getContext: () => ctx } as unknown as HTMLCanvasElement;
        await drawShareCard(canvas, { kartu: { ...kartu, rarity }, layout: 'kartu', format: 'story' });
        // Pearl backdrop gradients + glowing route stroke + corner mascot.
        expect(ctx.createLinearGradient).toHaveBeenCalled();
        expect(ctx.createRadialGradient).toHaveBeenCalled();
        expect(ctx.stroke).toHaveBeenCalled();
        expect(ctx.drawImage).toHaveBeenCalled();
    });

    it('still renders the kartu hero when the run has no GPS route', async () => {
        const ctx = makeCtx();
        const canvas = { width: 0, height: 0, getContext: () => ctx } as unknown as HTMLCanvasElement;
        await drawShareCard(canvas, { kartu: { ...kartu, polyline: null }, layout: 'kartu', format: 'story' });
        // Mascot grows into the empty window as the fallback hero.
        expect(ctx.drawImage).toHaveBeenCalled();
        expect(ctx.fillText).toHaveBeenCalled();
    });

    it('does not throw when the 2d context is unavailable', async () => {
        const canvas = { width: 0, height: 0, getContext: () => null } as unknown as HTMLCanvasElement;
        await expect(
            drawShareCard(canvas, { kartu, layout: 'rute', format: 'feed' }),
        ).resolves.toBeUndefined();
    });
});

describe('shareCardBlob', () => {
    it('renders onto an offscreen canvas and resolves the PNG blob', async () => {
        const ctx = makeCtx();
        const blob = new Blob(['png'], { type: 'image/png' });
        const canvas = {
            width: 0,
            height: 0,
            getContext: () => ctx,
            toBlob: (cb: (b: Blob | null) => void) => cb(blob),
        };
        vi.spyOn(document, 'createElement').mockReturnValue(canvas as unknown as HTMLCanvasElement);

        await expect(
            shareCardBlob({ kartu, layout: 'kartu', format: 'story' }),
        ).resolves.toBe(blob);
    });

    it('rejects when the canvas yields no blob', async () => {
        const ctx = makeCtx();
        const canvas = {
            width: 0,
            height: 0,
            getContext: () => ctx,
            toBlob: (cb: (b: Blob | null) => void) => cb(null),
        };
        vi.spyOn(document, 'createElement').mockReturnValue(canvas as unknown as HTMLCanvasElement);

        await expect(
            shareCardBlob({ kartu, layout: 'rute', format: 'feed' }),
        ).rejects.toThrow('toBlob failed');
    });
});

describe('drawShareCard — edge / branch cases', () => {
    it('shrinks an over-wide stat value to fit its column', async () => {
        // measureText returns a width far larger than any column, so
        // drawHeroStatGrid's value-shrink loop runs until vSize bottoms out.
        const ctx = makeCtx();
        ctx.measureText = vi.fn(() => ({ width: 9999 })) as unknown as typeof ctx.measureText;
        const canvas = { width: 0, height: 0, getContext: () => ctx } as unknown as HTMLCanvasElement;
        await drawShareCard(canvas, {
            kartu: { ...kartu, durasi: '39 menit 10 detik panjang sekali' },
            layout: 'kartu',
            format: 'story',
        });
        expect(ctx.fillText).toHaveBeenCalled();
    });

    it('skips the zone bar entirely when every zone is zero', async () => {
        const ctx = makeCtx();
        const canvas = { width: 0, height: 0, getContext: () => ctx } as unknown as HTMLCanvasElement;
        await drawShareCard(canvas, {
            kartu: { ...kartu, zonePct: { Z1: 0, Z2: 0, Z3: 0, Z4: 0, Z5: 0 } },
            layout: 'kartu',
            format: 'story',
        });
        // Still renders the rest of the hero; the bar just contributes no segments.
        expect(ctx.fillText).toHaveBeenCalled();
    });

    it('skips individual empty zone segments while drawing the bar', async () => {
        const ctx = makeCtx();
        const canvas = { width: 0, height: 0, getContext: () => ctx } as unknown as HTMLCanvasElement;
        await drawShareCard(canvas, {
            // Z2 and Z4 are zero — those segments are skipped, the rest drawn.
            kartu: { ...kartu, zonePct: { Z1: 30, Z2: 0, Z3: 40, Z4: 0, Z5: 30 } },
            layout: 'kartu',
            format: 'story',
        });
        expect(ctx.fillRect).toHaveBeenCalled();
    });

    it('renders the hero with a subtitle and without a quote', async () => {
        const ctx = makeCtx();
        const canvas = { width: 0, height: 0, getContext: () => ctx } as unknown as HTMLCanvasElement;
        await drawShareCard(canvas, {
            kartu: { ...kartu, subtitle: 'Long run minggu ini', quote: null },
            layout: 'kartu',
            format: 'story',
        });
        expect(ctx.fillText).toHaveBeenCalled();
    });

    it('renders a sparse hero with no zone bar, no badges, and no quote', async () => {
        const ctx = makeCtx();
        const canvas = { width: 0, height: 0, getContext: () => ctx } as unknown as HTMLCanvasElement;
        await drawShareCard(canvas, {
            kartu: { ...kartu, zonePct: null, tags: [], tagEmojis: [], quote: null },
            layout: 'kartu',
            format: 'feed',
        });
        expect(ctx.fillText).toHaveBeenCalled();
    });

    it('renders a hero with no stat cells (all stats absent)', async () => {
        const ctx = makeCtx();
        const canvas = { width: 0, height: 0, getContext: () => ctx } as unknown as HTMLCanvasElement;
        await drawShareCard(canvas, {
            kartu: {
                ...kartu,
                pace: null,
                hr: null,
                cadence: null,
                durasi: '—',
                fastestKm: null,
            },
            layout: 'kartu',
            format: 'feed',
        });
        expect(ctx.fillText).toHaveBeenCalled();
    });

    it('renders the rute layout without an edition, date, or quote', async () => {
        const ctx = makeCtx();
        const canvas = { width: 0, height: 0, getContext: () => ctx } as unknown as HTMLCanvasElement;
        await drawShareCard(canvas, {
            kartu: { ...kartu, edition: null, date: null, quote: null },
            layout: 'rute',
            format: 'story',
        });
        expect(ctx.fillText).toHaveBeenCalled();
    });

    it('renders the rute feed layout (trimmed stat cells, no story quote)', async () => {
        const ctx = makeCtx();
        const canvas = { width: 0, height: 0, getContext: () => ctx } as unknown as HTMLCanvasElement;
        await drawShareCard(canvas, { kartu, layout: 'rute', format: 'feed' });
        expect(ctx.fillText).toHaveBeenCalled();
    });

    it('falls back to the line colour for an unknown rarity', async () => {
        const ctx = makeCtx();
        const canvas = { width: 0, height: 0, getContext: () => ctx } as unknown as HTMLCanvasElement;
        await drawShareCard(canvas, {
            kartu: { ...kartu, rarity: 'mythic' as unknown as ShareKartuData['rarity'] },
            layout: 'rute',
            format: 'story',
        });
        expect(ctx.fillText).toHaveBeenCalled();
    });

    it.each(['kartu', 'rute'] as Layout[])(
        'draws the %s layout without a mascot/bunny when SVG glyph decode fails',
        async (layout) => {
            // Image that always errors -> loadBunny resolves null -> the hero
            // (kartu) skips its mascot and the rute brand lockup omits the bunny.
            class FailingImage {
                onload: (() => void) | null = null;
                onerror: (() => void) | null = null;
                set src(_v: string) {
                    queueMicrotask(() => this.onerror?.());
                }
            }
            (globalThis as unknown as { Image: unknown }).Image = FailingImage;
            const ctx = makeCtx();
            const canvas = { width: 0, height: 0, getContext: () => ctx } as unknown as HTMLCanvasElement;
            await drawShareCard(canvas, { kartu: { ...kartu, polyline: null }, layout, format: 'story' });
            expect(ctx.fillText).toHaveBeenCalled();
        },
    );

    it('prefers an explicit temariImg over the fallback bunny', async () => {
        const ctx = makeCtx();
        const canvas = { width: 0, height: 0, getContext: () => ctx } as unknown as HTMLCanvasElement;
        const temariImg = { naturalWidth: 120, naturalHeight: 200, width: 120, height: 200 } as HTMLImageElement;
        await drawShareCard(canvas, { kartu, layout: 'kartu', format: 'story', temariImg });
        expect(ctx.drawImage).toHaveBeenCalled();
    });

    it('renders the kartu hero with no edition (no floating edition pill)', async () => {
        const ctx = makeCtx();
        const canvas = { width: 0, height: 0, getContext: () => ctx } as unknown as HTMLCanvasElement;
        await drawShareCard(canvas, { kartu: { ...kartu, edition: null }, layout: 'kartu', format: 'story' });
        expect(ctx.fillText).toHaveBeenCalled();
    });

    it('renders the feed kartu hero with a subtitle (feed sizing branch)', async () => {
        const ctx = makeCtx();
        const canvas = { width: 0, height: 0, getContext: () => ctx } as unknown as HTMLCanvasElement;
        await drawShareCard(canvas, {
            kartu: { ...kartu, subtitle: 'Tempo Selasa' },
            layout: 'kartu',
            format: 'feed',
        });
        expect(ctx.fillText).toHaveBeenCalled();
    });

    it('renders the rute layout with no stat cells', async () => {
        const ctx = makeCtx();
        const canvas = { width: 0, height: 0, getContext: () => ctx } as unknown as HTMLCanvasElement;
        await drawShareCard(canvas, {
            kartu: { ...kartu, pace: null, hr: null, cadence: null, durasi: '—', fastestKm: null },
            layout: 'rute',
            format: 'story',
        });
        expect(ctx.fillText).toHaveBeenCalled();
    });

    it('handles a sparse zonePct missing some zone keys (treated as zero)', async () => {
        const ctx = makeCtx();
        const canvas = { width: 0, height: 0, getContext: () => ctx } as unknown as HTMLCanvasElement;
        await drawShareCard(canvas, {
            // Only Z2/Z3 present; the missing keys exercise the `?? 0` fallbacks.
            kartu: { ...kartu, zonePct: { Z2: 60, Z3: 40 } as ShareKartuData['zonePct'] },
            layout: 'kartu',
            format: 'story',
        });
        expect(ctx.fillRect).toHaveBeenCalled();
    });

    it('falls back to the ✦ emblem when a tag has no parallel emoji', async () => {
        const ctx = makeCtx();
        const canvas = { width: 0, height: 0, getContext: () => ctx } as unknown as HTMLCanvasElement;
        await drawShareCard(canvas, {
            // Two tags but only one emoji -> second pip uses the ✦ fallback.
            kartu: { ...kartu, tags: ['Anak Pagi', 'Kilat'], tagEmojis: ['🌅'] },
            layout: 'kartu',
            format: 'story',
        });
        expect(ctx.fillText).toHaveBeenCalled();
    });

    it('wraps an empty name without emitting a stray line', async () => {
        const ctx = makeCtx();
        const canvas = { width: 0, height: 0, getContext: () => ctx } as unknown as HTMLCanvasElement;
        await drawShareCard(canvas, { kartu: { ...kartu, name: '' }, layout: 'kartu', format: 'story' });
        expect(ctx.fillText).toHaveBeenCalled();
    });

    it.each(['kartu', 'rute'] as Layout[])(
        'draws a start dot and a hollow finish ring for a point-to-point route on %s, but only the dot for a loop',
        async (layout) => {
            const p2pCtx = makeCtx();
            await drawShareCard(
                { width: 0, height: 0, getContext: () => p2pCtx } as unknown as HTMLCanvasElement,
                { kartu: { ...kartu, polyline: pointToPointPolyline }, layout, format: 'story' },
            );
            const p2pArcCalls = p2pCtx.arc.mock.calls.length;

            const loopCtx = makeCtx();
            await drawShareCard(
                { width: 0, height: 0, getContext: () => loopCtx } as unknown as HTMLCanvasElement,
                { kartu: { ...kartu, polyline: loopPolyline }, layout, format: 'story' },
            );
            const loopArcCalls = loopCtx.arc.mock.calls.length;

            // Both routes draw the start dot; only the point-to-point route also
            // draws the hollow finish ring (mirrors RouteGlyph's `isPointToPoint`
            // gate) — so it issues exactly one more `arc` call than the loop.
            expect(p2pArcCalls).toBe(loopArcCalls + 1);
        },
    );

    it('thins the route stroke for a longer distanceKm and keeps it fixed without one', async () => {
        const shortRun = makeCtxWithLineWidthLog();
        await drawShareCard(
            { width: 0, height: 0, getContext: () => shortRun.ctx } as unknown as HTMLCanvasElement,
            { kartu: { ...kartu, distanceKm: 1 }, layout: 'kartu', format: 'story' },
        );

        const longRun = makeCtxWithLineWidthLog();
        await drawShareCard(
            { width: 0, height: 0, getContext: () => longRun.ctx } as unknown as HTMLCanvasElement,
            { kartu: { ...kartu, distanceKm: 20 }, layout: 'kartu', format: 'story' },
        );

        const noDistance = makeCtxWithLineWidthLog();
        await drawShareCard(
            { width: 0, height: 0, getContext: () => noDistance.ctx } as unknown as HTMLCanvasElement,
            { kartu: { ...kartu, distanceKm: null }, layout: 'kartu', format: 'story' },
        );

        // distanceKm=1 collapses RouteGlyph's log2 thinning to zero, so the base
        // (18px story) width is used, same as when distanceKm is absent entirely.
        expect(Math.max(...shortRun.widths)).toBeCloseTo(18, 0);
        expect(Math.max(...noDistance.widths)).toBeCloseTo(18, 0);
        // A longer run thins the stroke below that base width.
        expect(Math.max(...longRun.widths)).toBeLessThan(Math.max(...shortRun.widths));
    });

    it('draws the temanlari.app attribution handle on every export', async () => {
        const ctx = makeCtx();
        const canvas = { width: 0, height: 0, getContext: () => ctx } as unknown as HTMLCanvasElement;
        await drawShareCard(canvas, { kartu, layout: 'kartu', format: 'story' });
        expect(ctx.fillText).toHaveBeenCalledWith('temanlari.app', expect.any(Number), expect.any(Number));
    });
});
