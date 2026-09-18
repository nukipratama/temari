import type { Cell } from '@/lib/card/types';

import { DISPLAY, MONO } from '@/lib/card/palette';

/**
 * The drawing primitives the three card styles share, plus the geometry they
 * need (a fitted route path and a contour ring) and real text measurement.
 *
 * Everything emitted here is plain SVG a browser rasterises through an `Image`
 * onto a canvas: fills, strokes, gradients, clip paths and `feDropShadow`. No
 * external images, so the canvas stays untainted and `toBlob` works.
 *
 * Unlike the PHP renderer this replaces, a glyph advance can be measured here,
 * so a box, a budget or a step-down size is fitted to the text that goes in it
 * rather than guessed from a character count.
 */

export interface Font {
    family?: string;
    weight?: number;
    italic?: boolean;
    /** Per-character letter spacing, added after every glyph as SVG does. */
    tracking?: number;
}

/**
 * Advance widths at font-size 1, measured once per face+string. Measuring at
 * 1000px and dividing keeps the ratio stable enough to fit a size to a box in
 * one step instead of bisecting.
 */
const MEASURE_SIZE = 1000;
const advances = new Map<string, number>();
let measureContext: CanvasRenderingContext2D | null | undefined;

function context(): CanvasRenderingContext2D | null {
    if (measureContext === undefined) {
        measureContext =
            document.createElement('canvas').getContext('2d') ?? null;
    }
    return measureContext;
}

/** The width of one string at font-size 1, letter spacing excluded. */
function advance(value: string, font: Font): number {
    const family = font.family ?? MONO;
    const key = `${family}|${font.weight ?? 400}|${font.italic ? 'i' : 'n'}|${value}`;
    const cached = advances.get(key);
    if (cached !== undefined) return cached;

    const ctx = context();
    // jsdom has no text metrics; fall back to the monospaced advance the PHP
    // renderer used, so a unit test still gets a plausible, stable number.
    let width = value.length * 0.6;
    if (ctx !== null) {
        ctx.font = `${font.italic ? 'italic ' : ''}${font.weight ?? 400} ${MEASURE_SIZE}px "${family}"`;
        width = ctx.measureText(value).width / MEASURE_SIZE;
    }

    advances.set(key, width);
    return width;
}

/** The exact width a string occupies at `size`, letter spacing included. */
export function measure(value: string, size: number, font: Font = {}): number {
    return advance(value, font) * size + (font.tracking ?? 0) * value.length;
}

/**
 * The largest size up to `nominal` at which `value` still fits `available`.
 * Replaces the fixed size ladders the server renderer needed.
 */
export function fitSize(
    value: string,
    nominal: number,
    available: number,
    font: Font = {},
): number {
    const unit = advance(value, font);
    if (unit <= 0 || available <= 0) return nominal;

    return Math.min(nominal, available / unit);
}

/** A hard width budget for a variable-length label, with no ellipsis tail. */
export function truncate(
    value: string,
    size: number,
    available: number,
    font: Font = {},
): string {
    if (measure(value, size, font) <= available) return value;

    let clipped = value;
    while (clipped !== '' && measure(clipped, size, font) > available) {
        clipped = clipped.slice(0, -1);
    }

    return clipped;
}

/**
 * Join as many parts as fit the width budget, never splitting one. A legend
 * line would rather drop a badge than print half its name.
 */
export function joinWithin(
    parts: string[],
    glue: string,
    size: number,
    available: number,
    font: Font = {},
): string {
    let joined = '';
    for (const part of parts) {
        const candidate = joined === '' ? part : joined + glue + part;
        if (measure(candidate, size, font) > available) break;
        joined = candidate;
    }

    return joined;
}

/** Round to two decimals and drop the trailing zeros, to keep the SVG small. */
export function num(value: number): string {
    const formatted = value.toFixed(2);

    return formatted.includes('.')
        ? formatted.replace(/\.?0+$/, '')
        : formatted;
}

export function escape(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&apos;');
}

type Attributes = Record<string, string | number | null | undefined>;

function tag(name: string, attributes: Attributes, inner?: string): string {
    let pairs = '';
    for (const [key, value] of Object.entries(attributes)) {
        if (value === null || value === undefined || value === '') continue;
        pairs += ` ${key}="${value}"`;
    }

    return inner === undefined
        ? `<${name}${pairs}/>`
        : `<${name}${pairs}>${inner}</${name}>`;
}

export function doc(
    width: number,
    height: number,
    body: string,
    defs = '',
): string {
    const defsBlock = defs === '' ? '' : `<defs>${defs}</defs>`;

    return (
        `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" ` +
        `viewBox="0 0 ${width} ${height}">${defsBlock}${body}</svg>`
    );
}

export interface TextOptions extends Font {
    anchor?: 'start' | 'middle' | 'end';
    opacity?: number;
    transform?: string;
}

export function text(
    value: string,
    x: number,
    y: number,
    size: number,
    fill: string,
    options: TextOptions = {},
): string {
    // A label with nothing to say draws nothing. Guarding here rather than at
    // each of the three styles' call sites means no block can ship a caption
    // over an empty value.
    if (value.trim() === '') return '';

    return tag(
        'text',
        {
            x: num(x),
            y: num(y),
            'font-family': options.family ?? MONO,
            'font-size': num(size),
            'font-weight': options.weight ?? 400,
            'font-style': options.italic ? 'italic' : null,
            'text-anchor':
                options.anchor === undefined || options.anchor === 'start'
                    ? null
                    : options.anchor,
            'letter-spacing':
                options.tracking === undefined ? null : num(options.tracking),
            fill,
            'fill-opacity':
                options.opacity === undefined ? null : num(options.opacity),
            transform: options.transform,
        },
        escape(value),
    );
}

/** The brand wordmark, the one place Fraunces is set. */
export function wordmark(
    x: number,
    y: number,
    size: number,
    fill: string,
    anchor: TextOptions['anchor'] = 'start',
): string {
    return text('temari', x, y, size, fill, {
        family: DISPLAY,
        weight: 600,
        italic: true,
        anchor,
    });
}

export interface ShapeOptions {
    fill?: string;
    stroke?: string;
    strokeWidth?: number;
    radius?: number;
    opacity?: number;
    strokeOpacity?: number;
    dash?: string;
}

export function rect(
    x: number,
    y: number,
    width: number,
    height: number,
    options: ShapeOptions = {},
): string {
    return tag('rect', {
        x: num(x),
        y: num(y),
        width: num(width),
        height: num(height),
        rx: options.radius === undefined ? null : num(options.radius),
        fill: options.fill ?? 'none',
        stroke: options.stroke,
        'stroke-width':
            options.strokeWidth === undefined ? null : num(options.strokeWidth),
        'stroke-dasharray': options.dash,
        'fill-opacity':
            options.opacity === undefined ? null : num(options.opacity),
        'stroke-opacity':
            options.strokeOpacity === undefined
                ? null
                : num(options.strokeOpacity),
    });
}

export function line(
    x1: number,
    y1: number,
    x2: number,
    y2: number,
    stroke: string,
    options: {
        width?: number;
        opacity?: number;
        dash?: string;
        cap?: string;
    } = {},
): string {
    return tag('line', {
        x1: num(x1),
        y1: num(y1),
        x2: num(x2),
        y2: num(y2),
        stroke,
        'stroke-width': num(options.width ?? 1),
        'stroke-opacity':
            options.opacity === undefined ? null : num(options.opacity),
        'stroke-dasharray': options.dash,
        'stroke-linecap': options.cap,
    });
}

export function circle(
    cx: number,
    cy: number,
    radius: number,
    options: ShapeOptions = {},
): string {
    return tag('circle', {
        cx: num(cx),
        cy: num(cy),
        r: num(radius),
        fill: options.fill ?? 'none',
        stroke: options.stroke,
        'stroke-width':
            options.strokeWidth === undefined ? null : num(options.strokeWidth),
        'fill-opacity':
            options.opacity === undefined ? null : num(options.opacity),
        'stroke-opacity':
            options.strokeOpacity === undefined
                ? null
                : num(options.strokeOpacity),
    });
}

export function path(d: string, options: ShapeOptions = {}): string {
    return tag('path', {
        d,
        fill: options.fill ?? 'none',
        stroke: options.stroke,
        'stroke-width':
            options.strokeWidth === undefined ? null : num(options.strokeWidth),
        'fill-opacity':
            options.opacity === undefined ? null : num(options.opacity),
        'stroke-opacity':
            options.strokeOpacity === undefined
                ? null
                : num(options.strokeOpacity),
        'stroke-dasharray': options.dash,
        'stroke-linecap': 'round',
        'stroke-linejoin': 'round',
    });
}

export function group(
    inner: string,
    options: { transform?: string; clip?: string } = {},
): string {
    return tag(
        'g',
        { transform: options.transform, 'clip-path': options.clip },
        inner,
    );
}

export type Point = [number, number];

/** A route path out of already-projected points. */
export function polylinePath(points: Point[], close = false): string {
    if (points.length === 0) return '';

    let d = `M${num(points[0][0])},${num(points[0][1])}`;
    for (const [x, y] of points.slice(1)) {
        d += `L${num(x)},${num(y)}`;
    }

    return close ? `${d}Z` : d;
}

/**
 * A deterministic 0..1 sequence — the same linear congruential generator the
 * design round drew its contours with, so a given seed always draws the same
 * landscape.
 */
function seeded(seed: number): () => number {
    let state = seed * 9301 + 49297;

    return () => {
        state = (state * 9301 + 49297) % 233280;
        return state / 233280;
    };
}

/**
 * A closed, gently irregular ring — style C's contour terrain. Three summed
 * harmonics off a seeded sequence.
 */
export function closedLoop(
    cx: number,
    cy: number,
    rx: number,
    ry: number,
    seed: number,
    wobble = 0.16,
): string {
    const random = seeded(seed + 3);
    const amplitudes = [wobble, wobble * 0.6, wobble * 0.35];
    const harmonics = [
        2 + Math.trunc(random() * 3),
        4 + Math.trunc(random() * 3),
        7 + Math.trunc(random() * 3),
    ];
    const phases = [random() * 6.3, random() * 6.3, random() * 6.3];

    const points: Point[] = [];
    const steps = 46;
    for (let i = 0; i < steps; i++) {
        const theta = (i / steps) * Math.PI * 2;
        let magnitude = 1;
        for (let h = 0; h < 3; h++) {
            magnitude +=
                amplitudes[h] * Math.sin(harmonics[h] * theta + phases[h]);
        }
        points.push([
            cx + Math.cos(theta) * rx * magnitude,
            cy + Math.sin(theta) * ry * magnitude,
        ]);
    }

    return polylinePath(points, true);
}

/** Evenly spaced picks along a point list, for km ticks and split dots. */
export function along(points: Point[], count: number): Point[] {
    if (points.length === 0 || count < 1) return [];

    const picked: Point[] = [];
    for (let i = 1; i <= count; i++) {
        picked.push(
            points[Math.round((points.length - 1) * (i / (count + 1)))],
        );
    }

    return picked;
}

/**
 * Whether a projected trace ends where it started, within a loose fraction of
 * the box diagonal — a loop run rather than an out-and-back.
 */
export function isClosedLoop(
    points: Point[],
    width: number,
    height: number,
): boolean {
    if (points.length < 2) return false;

    const first = points[0];
    const last = points[points.length - 1];

    return (
        Math.hypot(last[0] - first[0], last[1] - first[1]) <=
        Math.hypot(width, height) * 0.03
    );
}

/**
 * A label/value list with the empty-valued entries dropped, so a ruled row
 * never divides its width by a cell it cannot fill.
 */
export function filledCells(cells: Cell[]): Cell[] {
    return cells.filter(([, value]) => value.trim() !== '');
}

/**
 * The contour seed off the print's own serial. Really CRC-32 rather than any
 * cheaper hash, so a plate drawn here lands on the same landscape the server
 * renderer drew for the same card.
 */
export function serialSeed(serial: string): number {
    let crc = 0xffffffff;
    for (let i = 0; i < serial.length; i++) {
        crc ^= serial.charCodeAt(i);
        for (let bit = 0; bit < 8; bit++) {
            crc = crc & 1 ? (crc >>> 1) ^ 0xedb88320 : crc >>> 1;
        }
    }

    return ((crc ^ 0xffffffff) >>> 0) % 997;
}
