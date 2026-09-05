/* Rasterises the app icon and favicon from resources/brand/logo/, so every
   shipped icon comes from the same SVG the in-app TemariMark draws.

   Run inside the Sail container, which has Alpine's native chromium:
     ./vendor/bin/sail exec app node resources/brand/build-icons.mjs           */
import { readFileSync, writeFileSync } from 'node:fs';
import { chromium } from 'playwright';

const LOGO = new URL('./logo/', import.meta.url);
const PUBLIC = new URL('../../public/', import.meta.url);

/* apple-touch-icon is 180 by convention; the manifest declares 192 and 512.
   The maskable variant is the same art: the mark occupies the middle ~55% of
   the 1024 canvas, well inside the 80% safe zone a maskable icon must respect,
   so it needs no separately-padded source. */
const PNGS = [
  ['temari-app-icon.svg', 'apple-touch-icon.png', 180],
  ['temari-app-icon.svg', 'icon-192.png', 192],
  ['temari-app-icon.svg', 'icon-512.png', 512],
  ['temari-app-icon.svg', 'icon-maskable-512.png', 512],
];

/* Windows and older browsers still ask for favicon.ico. Since Vista an ICO may
   hold PNG frames verbatim, so the container is a header plus one directory
   entry per size rather than a bitmap encoder. */
const ICO_SIZES = [16, 32, 48];

const browser = await chromium.launch({
  executablePath: '/usr/bin/chromium',
  args: ['--no-sandbox', '--disable-dev-shm-usage'],
});

async function render(svgName, size) {
  const svg = readFileSync(new URL(svgName, LOGO), 'utf8');
  const page = await browser.newPage({
    viewport: { width: size, height: size },
    deviceScaleFactor: 1,
  });
  await page.setContent(
    `<!doctype html><style>html,body{margin:0;padding:0}svg{display:block;width:${size}px;height:${size}px}</style>${svg}`,
    { waitUntil: 'load' },
  );
  const buffer = await page.locator('svg').screenshot({ omitBackground: true });
  await page.close();
  return buffer;
}

function ico(frames) {
  const header = Buffer.alloc(6);
  header.writeUInt16LE(0, 0);
  header.writeUInt16LE(1, 2); // 1 = icon
  header.writeUInt16LE(frames.length, 4);

  let offset = 6 + frames.length * 16;
  const entries = [];
  for (const { size, png } of frames) {
    const entry = Buffer.alloc(16);
    entry.writeUInt8(size >= 256 ? 0 : size, 0);
    entry.writeUInt8(size >= 256 ? 0 : size, 1);
    entry.writeUInt8(0, 2); // palette
    entry.writeUInt8(0, 3); // reserved
    entry.writeUInt16LE(1, 4); // colour planes
    entry.writeUInt16LE(32, 6); // bits per pixel
    entry.writeUInt32LE(png.length, 8);
    entry.writeUInt32LE(offset, 12);
    offset += png.length;
    entries.push(entry);
  }

  return Buffer.concat([header, ...entries, ...frames.map((f) => f.png)]);
}

for (const [svgName, out, size] of PNGS) {
  const png = await render(svgName, size);
  writeFileSync(new URL(out, PUBLIC), png);
  console.log(`wrote ${out} (${size}x${size}, ${png.length} bytes)`);
}

const frames = [];
for (const size of ICO_SIZES) {
  frames.push({ size, png: await render('temari-favicon.svg', size) });
}
const icoBuffer = ico(frames);
writeFileSync(new URL('favicon.ico', PUBLIC), icoBuffer);
console.log(`wrote favicon.ico (${ICO_SIZES.join('/')}, ${icoBuffer.length} bytes)`);

await browser.close();
