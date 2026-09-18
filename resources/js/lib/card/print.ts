import frauncesItalicUrl from '@fontsource-variable/fraunces/files/fraunces-latin-standard-italic.woff2?url';
import jetbrainsMonoUrl from '@fontsource-variable/jetbrains-mono/files/jetbrains-mono-latin-wght-normal.woff2?url';
import plusJakartaUrl from '@fontsource-variable/plus-jakarta-sans/files/plus-jakarta-sans-latin-wght-normal.woff2?url';

import { printFacts } from '@/lib/card/facts';
import { DISPLAY, MONO, SANS } from '@/lib/card/palette';
import { renderBroadsheet } from '@/lib/card/styles/broadsheet';
import { renderTicket } from '@/lib/card/styles/ticket';
import { renderTopoPlate } from '@/lib/card/styles/topo';
import {
    CARD_WIDTH,
    cardHeight,
    type CardAspect,
    type CardFactsPayload,
    type CardOptions,
    type CardStyle,
} from '@/lib/card/types';

/**
 * One print, drawn in the browser: the chosen style composes an SVG at the
 * aspect's exact pixel size, and the canvas turns it into the PNG that share,
 * copy and download all hand out. Preview and export are the same bytes, so
 * there is one renderer again and nothing to drift.
 */

export interface Print {
    blob: Blob;
    /** Object URL for the blob. Revoked by whoever holds the memo. */
    url: string;
    width: number;
    height: number;
}

const RENDERERS = {
    broadsheet: renderBroadsheet,
    ticket: renderTicket,
    topo: renderTopoPlate,
} as const;

/** The SVG a style draws for these facts, without the embedded font faces. */
export function buildCardSvg(
    payload: CardFactsPayload,
    options: CardOptions,
    style: CardStyle,
    aspect: CardAspect,
): string {
    return RENDERERS[style](printFacts(payload, options), aspect);
}

/**
 * An SVG loaded into an `Image` renders in its own document, which cannot see
 * the page's web fonts — naming the families is not enough, so the faces ride
 * along inside the file as data URLs. Fetched once and held, since the same
 * three faces serve every print.
 */
let embeddedFaces: Promise<string> | null = null;

async function dataUrl(url: string): Promise<string> {
    const response = await fetch(url);
    const buffer = new Uint8Array(await response.arrayBuffer());
    let binary = '';
    for (const byte of buffer) binary += String.fromCharCode(byte);

    return `data:font/woff2;base64,${btoa(binary)}`;
}

function face(
    family: string,
    style: 'normal' | 'italic',
    weight: string,
    src: string,
): string {
    return `@font-face{font-family:'${family}';font-style:${style};font-weight:${weight};src:url(${src}) format('woff2');}`;
}

async function fontFaces(): Promise<string> {
    embeddedFaces ??= (async () => {
        const [display, sans, mono] = await Promise.all([
            dataUrl(frauncesItalicUrl),
            dataUrl(plusJakartaUrl),
            dataUrl(jetbrainsMonoUrl),
        ]);

        return (
            face(DISPLAY, 'italic', '100 900', display) +
            face(SANS, 'normal', '200 800', sans) +
            face(MONO, 'normal', '100 800', mono)
        );
    })().catch(() => '');

    return embeddedFaces;
}

/** Test seam: forget the held faces so a failed fetch isn't cached forever. */
export function resetEmbeddedFonts(): void {
    embeddedFaces = null;
}

function withFonts(svg: string, css: string): string {
    if (css === '') return svg;

    const open = svg.indexOf('>') + 1;

    return `${svg.slice(0, open)}<style>${css}</style>${svg.slice(open)}`;
}

function rasterise(
    svg: string,
    width: number,
    height: number,
): Promise<Print> {
    return new Promise<Print>((resolve, reject) => {
        const source = URL.createObjectURL(
            new Blob([svg], { type: 'image/svg+xml;charset=utf-8' }),
        );
        const image = new Image();

        image.onload = () => {
            URL.revokeObjectURL(source);
            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            const ctx = canvas.getContext('2d');
            if (ctx === null) {
                reject(new Error('no 2d context'));
                return;
            }
            ctx.drawImage(image, 0, 0, width, height);
            canvas.toBlob((blob) => {
                if (blob === null) {
                    reject(new Error('canvas produced no png'));
                    return;
                }
                resolve({
                    blob,
                    url: URL.createObjectURL(blob),
                    width,
                    height,
                });
            }, 'image/png');
        };
        image.onerror = () => {
            URL.revokeObjectURL(source);
            reject(new Error('svg failed to load'));
        };
        image.src = source;
    });
}

/**
 * The PNG for one print. Waits on `document.fonts.ready` first: the measurement
 * the styles fit their boxes with reads the page's faces, and asking before
 * they load silently measures a fallback.
 */
export async function renderPrint(
    payload: CardFactsPayload,
    options: CardOptions,
    style: CardStyle,
    aspect: CardAspect,
): Promise<Print> {
    await document.fonts?.ready;

    const svg = withFonts(
        buildCardSvg(payload, options, style, aspect),
        await fontFaces(),
    );

    return rasterise(svg, CARD_WIDTH, cardHeight(aspect));
}

/** The memo key: a print is fully described by its style, aspect and facts. */
export function printKey(
    style: CardStyle,
    aspect: CardAspect,
    options: CardOptions,
): string {
    return [
        style,
        aspect,
        options.hr ? '1' : '0',
        options.elevation ? '1' : '0',
        options.weather ? '1' : '0',
        options.badges ? '1' : '0',
    ].join(':');
}
