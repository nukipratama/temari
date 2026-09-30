/**
 * The browser-side scans, as source strings, so the per-page scripts and the
 * state driver run byte-identical checks.
 *
 * These are strings rather than functions because they are evaluated inside the
 * page, where nothing from this module scope exists. Keeping them here means a
 * fix to the colour maths lands everywhere at once — the regex-vs-canvas bug
 * below had already been fixed in one scanner and not the other.
 */

/**
 * Colour helpers, shared by every scan.
 *
 * `rgb()` resolves through a canvas rather than parsing the string. Computed
 * styles come back as `oklab(...)` and `color-mix(...)` at least as often as
 * `rgb()`, and a regex that only knows `rgb()` silently drops them — which is
 * how a border at 1.02:1 read as "no data" instead of "invisible".
 */
export const HELPERS = `
    const __probe = document.createElement('canvas').getContext('2d', {
        willReadFrequently: true,
    });
    const rgb = (v) => {
        if (!v || v === 'none' || v === 'transparent') return null;
        __probe.clearRect(0, 0, 1, 1);
        __probe.fillRect(0, 0, 1, 1);
        const before = __probe.getImageData(0, 0, 1, 1).data.join();
        __probe.clearRect(0, 0, 1, 1);
        __probe.fillStyle = v;
        __probe.fillRect(0, 0, 1, 1);
        const [r, g, b, a] = __probe.getImageData(0, 0, 1, 1).data;
        if ([r, g, b, a].join() === before) return null;
        return { c: [r, g, b], a: a / 255 };
    };
    const over = (fg, bg) => fg.c.map((v, i) => v * fg.a + bg[i] * (1 - fg.a));
    const lum = (c) => {
        const s = c.map((v) => {
            const x = v / 255;
            return x <= 0.03928 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4;
        });
        return 0.2126 * s[0] + 0.7152 * s[1] + 0.0722 * s[2];
    };
    const ratio = (a, b) => {
        const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p);
        return (x + 0.05) / (y + 0.05);
    };
    /** The opaque colour behind \`el\`, compositing translucent layers on the way up. */
    const behind = (el, includeSelf) => {
        const stack = [];
        let node = includeSelf ? el : el.parentElement;
        while (node) {
            const c = rgb(getComputedStyle(node).backgroundColor);
            if (c && c.a > 0) {
                if (c.a === 1) {
                    let out = c.c;
                    for (const layer of stack.reverse()) out = over(layer, out);
                    return out;
                }
                stack.push(c);
            }
            node = node.parentElement;
        }
        return null;
    };
`;

/** Surfaces far lighter than the ground beneath them — see light-islands.mjs. */
export const ISLANDS = `(() => {
    ${HELPERS}
    const out = [];
    for (const el of document.querySelectorAll('*')) {
        const style = getComputedStyle(el);
        const own = rgb(style.backgroundColor);
        if (!own || own.a < 0.95) continue;
        const ownLum = lum(own.c);
        if (ownLum < 0.3) continue;
        const box = el.getBoundingClientRect();
        if (box.width < 6 || box.height < 6) continue;
        const ground = behind(el, false);
        if (!ground || lum(ground) > 0.15) continue;
        out.push({
            kind: 'island',
            cls: (el.className?.toString?.() ?? '').slice(0, 130),
            detail: style.backgroundColor,
            score: Math.round(ownLum * 100) / 100,
            size: Math.round(box.width) + 'x' + Math.round(box.height),
        });
    }
    return out;
})()`;

/** Borders under the separator floor against what is outside them — see edges.mjs. */
export const EDGES = (min = 1.4) => `(() => {
    ${HELPERS}
    const out = [];
    for (const el of document.querySelectorAll('*')) {
        const s = getComputedStyle(el);
        const box = el.getBoundingClientRect();
        if (box.width < 8 || box.height < 8) continue;
        let colour = null;
        for (const side of ['Top', 'Right', 'Bottom', 'Left']) {
            if (parseFloat(s['border' + side + 'Width']) > 0) {
                colour = s['border' + side + 'Color'];
                break;
            }
        }
        if (!colour) continue;
        const c = rgb(colour);
        if (!c || c.a === 0) continue;
        const ground = behind(el, false);
        if (!ground) continue;
        const r = ratio(over(c, ground), ground);
        if (r >= ${min}) continue;
        out.push({
            kind: 'edge',
            cls: (el.className?.toString?.() ?? '').slice(0, 130),
            detail: colour,
            score: Math.round(r * 100) / 100,
            size: Math.round(box.width) + 'x' + Math.round(box.height),
        });
    }
    return out;
})()`;
