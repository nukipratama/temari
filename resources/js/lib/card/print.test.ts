import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    buildCardSvg,
    printKey,
    renderPrint,
    resetEmbeddedFonts,
} from '@/lib/card/print';
import {
    ALL_FACTS,
    CARD_ASPECTS,
    CARD_STYLES,
    CARD_WIDTH,
    cardHeight,
    type RunForm,
} from '@/lib/card/types';
import { makeCardFacts } from '@/test/cardFacts';

const FORMS: RunForm[] = ['easy', 'long', 'race', 'pr', 'nogps'];

const NO_FACTS = {
    hr: false,
    elevation: false,
    weather: false,
    badges: false,
};

const forForm = (form: RunForm) =>
    makeCardFacts({
        form,
        polyline: form === 'nogps' ? null : makeCardFacts().polyline,
        race_name: form === 'race' ? 'JAKARTA CITY 10K' : null,
        race_distance: form === 'race' ? '10K' : null,
    });

describe('card svg', () => {
    it('draws every style, form and aspect at the export size', () => {
        for (const style of CARD_STYLES) {
            for (const form of FORMS) {
                for (const aspect of CARD_ASPECTS) {
                    const svg = buildCardSvg(
                        forForm(form),
                        ALL_FACTS,
                        style,
                        aspect,
                    );

                    expect(
                        svg,
                        `${style}/${form}/${aspect}`,
                    ).toContain(
                        `width="${CARD_WIDTH}" height="${cardHeight(aspect)}" viewBox="0 0 ${CARD_WIDTH} ${cardHeight(aspect)}"`,
                    );
                }
            }
        }
    });

    it('never captions a block it has no value for, with any chip set', () => {
        for (const options of [ALL_FACTS, NO_FACTS]) {
            for (const style of CARD_STYLES) {
                for (const form of FORMS) {
                    for (const aspect of CARD_ASPECTS) {
                        const svg = buildCardSvg(
                            forForm(form),
                            options,
                            style,
                            aspect,
                        );

                        // An empty text node is what a labelled void looks like
                        // once the SVG is written out.
                        expect(
                            svg,
                            `${style}/${form}/${aspect}`,
                        ).not.toContain('></text>');
                    }
                }
            }
        }
    });

    it('keys a print by its style, aspect and facts, and nothing else', () => {
        expect(printKey('ticket', 'story', ALL_FACTS)).toBe(
            'ticket:story:1:1:1:1',
        );
        expect(printKey('ticket', 'story', { ...ALL_FACTS, hr: false })).not.toBe(
            printKey('ticket', 'story', ALL_FACTS),
        );
        expect(printKey('ticket', 'feed', ALL_FACTS)).not.toBe(
            printKey('ticket', 'story', ALL_FACTS),
        );
    });
});

describe('rasterising', () => {
    let drawn: string;
    let fontsResolved: boolean;

    beforeEach(() => {
        resetEmbeddedFonts();
        drawn = '';
        fontsResolved = false;

        vi.stubGlobal('fetch', async () => ({
            arrayBuffer: async () => new Uint8Array([1, 2, 3]).buffer,
        }));
        vi.spyOn(URL, 'createObjectURL').mockImplementation((blob) => {
            if (blob instanceof Blob && blob.type.startsWith('image/svg')) {
                void blob.text().then((body) => {
                    drawn = body;
                });
            }
            return 'blob:stub';
        });
        vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {});
        Object.defineProperty(document, 'fonts', {
            configurable: true,
            value: {
                ready: Promise.resolve().then(() => {
                    fontsResolved = true;
                }),
            },
        });
        vi.spyOn(
            HTMLCanvasElement.prototype,
            'getContext',
        ).mockReturnValue({ drawImage: vi.fn() } as never);
        vi.spyOn(HTMLCanvasElement.prototype, 'toBlob').mockImplementation(
            (callback) => callback(new Blob(['png'], { type: 'image/png' })),
        );
        // jsdom never loads an image; fire the handler the next tick instead.
        Object.defineProperty(globalThis.Image.prototype, 'src', {
            configurable: true,
            set(this: HTMLImageElement) {
                setTimeout(() => this.onload?.(new Event('load')), 0);
            },
        });
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    it('waits for the page fonts before it measures and draws', async () => {
        const print = await renderPrint(
            makeCardFacts(),
            ALL_FACTS,
            'broadsheet',
            'story',
        );

        expect(fontsResolved).toBe(true);
        expect(print.width).toBe(CARD_WIDTH);
        expect(print.height).toBe(1920);
        expect(print.blob.type).toBe('image/png');
    });

    it('carries the faces inside the file, since an <img> SVG has no page fonts', async () => {
        await renderPrint(makeCardFacts(), ALL_FACTS, 'ticket', 'feed');

        expect(drawn).toContain('@font-face');
        expect(drawn).toContain("font-family:'JetBrains Mono'");
        expect(drawn).toContain('data:font/woff2;base64,');
    });

    it('hands back a png at the aspect size for every style, form and aspect', async () => {
        for (const style of CARD_STYLES) {
            for (const form of FORMS) {
                for (const aspect of CARD_ASPECTS) {
                    const print = await renderPrint(
                        forForm(form),
                        ALL_FACTS,
                        style,
                        aspect,
                    );

                    expect(
                        [print.width, print.height, print.blob.type],
                        `${style}/${form}/${aspect}`,
                    ).toEqual([CARD_WIDTH, cardHeight(aspect), 'image/png']);
                }
            }
        }
    });

    it('still draws when the faces cannot be fetched', async () => {
        vi.stubGlobal('fetch', async () => {
            throw new Error('offline');
        });
        resetEmbeddedFonts();

        const print = await renderPrint(
            makeCardFacts(),
            ALL_FACTS,
            'broadsheet',
            'feed',
        );

        expect(print.blob.type).toBe('image/png');
        expect(drawn).not.toContain('@font-face');
    });

    it('rejects when the svg will not load', async () => {
        Object.defineProperty(globalThis.Image.prototype, 'src', {
            configurable: true,
            set(this: HTMLImageElement) {
                setTimeout(() => this.onerror?.(new Event('error')), 0);
            },
        });

        await expect(
            renderPrint(makeCardFacts(), ALL_FACTS, 'topo', 'story'),
        ).rejects.toThrow('svg failed to load');
    });

    it('rejects when the canvas hands back no png', async () => {
        vi.spyOn(HTMLCanvasElement.prototype, 'toBlob').mockImplementation(
            (callback) => callback(null),
        );

        await expect(
            renderPrint(makeCardFacts(), ALL_FACTS, 'ticket', 'story'),
        ).rejects.toThrow('canvas produced no png');
    });

    it('rejects when the canvas has no 2d context', async () => {
        vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(
            null,
        );

        await expect(
            renderPrint(makeCardFacts(), ALL_FACTS, 'ticket', 'story'),
        ).rejects.toThrow('no 2d context');
    });
});
