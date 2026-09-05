/* Default social preview card (public/og-default.png), 1200x630.
   Run: ./vendor/bin/sail exec app node resources/brand/build-og.mjs

   Colors come from build-tokens.mjs and the mark path data is read out of
   logo/temari-mark.svg, so neither can drift from the shipped brand. Text is
   rendered by librsvg through fontconfig, which resolves the families the
   Dockerfile installs (see fonts/README.md). */

import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { COLOR } from './build-tokens.mjs';

const HERE = fileURLToPath(new URL('.', import.meta.url));
const OUT = join(HERE, '../../public/og-default.png');

const W = 1200;
const H = 630;
const PAD = 88;

const SKY = COLOR.sky;
const SKY_DEEP = COLOR['sky-deep'];
const GOLD = COLOR.horizon;
const CREAM = COLOR.cream;

const FONT_SANS = 'Plus Jakarta Sans';
const FONT_DISPLAY = 'Fraunces';

/** The three ball arcs, read from the shipped mark so the geometry cannot drift. */
function markPaths() {
    const svg = readFileSync(join(HERE, 'logo/temari-mark.svg'), 'utf8');
    const paths = [...svg.matchAll(/<path\s+class="(mark-lead|mark-base)"\s+d="([^"]+)"/g)];

    if (paths.length !== 3) {
        throw new Error(`expected 3 mark paths in temari-mark.svg, found ${paths.length}`);
    }

    return paths.map(([, role, d]) => ({ d, stroke: role === 'mark-lead' ? GOLD : CREAM }));
}

const MARK_SIZE = 76;

const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}">
  <defs>
    <linearGradient id="ground" x1="0" y1="0" x2="0.35" y2="1">
      <stop offset="0" stop-color="${SKY}"/>
      <stop offset="1" stop-color="${SKY_DEEP}"/>
    </linearGradient>
    <radialGradient id="glow" cx="0.82" cy="0.16" r="0.62">
      <stop offset="0" stop-color="${GOLD}" stop-opacity="0.30"/>
      <stop offset="1" stop-color="${GOLD}" stop-opacity="0"/>
    </radialGradient>
  </defs>

  <rect width="${W}" height="${H}" fill="url(#ground)"/>
  <rect width="${W}" height="${H}" fill="url(#glow)"/>

  <g transform="translate(${PAD} 74) scale(${MARK_SIZE / 100})" fill="none" stroke-width="9" stroke-linecap="round">
    ${markPaths()
        .map((p) => `<path stroke="${p.stroke}" d="${p.d}"/>`)
        .join('\n    ')}
  </g>
  <text x="${PAD + MARK_SIZE + 22}" y="${74 + MARK_SIZE - 18}"
        font-family="${FONT_SANS}" font-size="52" font-weight="800"
        letter-spacing="-1.2" fill="${CREAM}">temari</text>

  <text x="${PAD}" y="374" font-family="${FONT_SANS}" font-size="112"
        font-weight="700" letter-spacing="-3" fill="${CREAM}">You vs</text>
  <text x="${PAD}" y="492" font-family="${FONT_DISPLAY}" font-size="112"
        font-style="italic" font-weight="500" letter-spacing="-2" fill="${GOLD}">past you.</text>

  <rect x="${PAD}" y="${H - 96}" width="132" height="4" rx="2" fill="${GOLD}" opacity="0.85"/>
</svg>
`;

const tmp = mkdtempSync(join(tmpdir(), 'temari-og-'));
try {
    const svgPath = join(tmp, 'og.svg');
    writeFileSync(svgPath, svg);
    execFileSync('magick', ['-background', 'none', `${svgPath}`, '-strip', `PNG24:${OUT}`], {
        stdio: 'inherit',
    });
    console.log(`wrote public/og-default.png (${W}x${H})`);
} finally {
    rmSync(tmp, { recursive: true, force: true });
}
