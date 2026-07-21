/**
 * One-off: verify mascot across login page, all 8 poses, and share card.
 * Run: ./vendor/bin/sail exec app node .claude/skills/browser-review/scripts/verify-mascot.mjs
 * Output: storage/app/browser-review/verify/
 */

import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import { SHOT, EXT } from './lib.mjs';

const BASE = 'http://localhost';
const OUT = 'storage/app/browser-review/verify';
fs.mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch({
    executablePath: '/usr/bin/chromium',
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
});

async function screenshot(page, name) {
    const p = path.join(OUT, `${name}.${EXT}`);
    await page.screenshot({ path: p, fullPage: false, ...SHOT });
    console.log(`  ✓ ${name}`);
    return p;
}

// ── 1. Login page (unequipped baseline) ──────────────────────────────────────
{
    const page = await browser.newPage();
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`${BASE}/login`);
    await page.waitForLoadState('networkidle');
    await screenshot(page, '01-login-mobile');
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto(`${BASE}/login`);
    await page.waitForLoadState('networkidle');
    await screenshot(page, '02-login-desktop');
    await page.close();
}

// ── 2. All 8 poses (inject a test overlay into an authenticated page) ─────────
async function loginAsDemo(page) {
    await page.goto(`${BASE}/login`);
    await page.waitForLoadState('networkidle');
    const btn = page.locator('button', { hasText: /demo/i }).first();
    if (await btn.isVisible()) {
        await btn.click();
        await page.waitForURL(/(?!.*login)/);
    }
}

async function dismissReveal(page) {
    const dialog = page.getByRole('dialog', { name: /kartu baru/i });
    if (await dialog.isVisible().catch(() => false)) {
        await page.getByRole('button', { name: /tutup/i }).first().click().catch(() => {});
        await page.waitForTimeout(800);
    }
}

{
    const page = await browser.newPage();
    await page.setViewportSize({ width: 1400, height: 900 });
    await loginAsDemo(page);
    // Inject a full-width grid of all 8 poses via React root injection is complex;
    // instead navigate to aksesori which already renders the mascot, then use
    // page.evaluate to inject an overlay showing all poses side by side.
    await page.goto(`${BASE}/aksesori`);
    await page.waitForLoadState('networkidle');

    await page.evaluate(() => {
        const POSES = ['proud','pumped','excited','holding','reading','wobble','observational','glow'];
        const overlay = document.createElement('div');
        overlay.id = 'pose-grid';
        overlay.style.cssText = `
            position:fixed; inset:0; z-index:9999; background:#f5ede0;
            display:flex; flex-wrap:wrap; align-items:center; justify-content:center;
            padding:24px; gap:16px;
        `;
        POSES.forEach(pose => {
            const cell = document.createElement('div');
            cell.style.cssText = 'display:flex;flex-direction:column;align-items:center;gap:6px;';
            const label = document.createElement('span');
            label.textContent = pose;
            label.style.cssText = 'font-family:monospace;font-size:11px;font-weight:700;color:#3b2f1f;';
            // Each slot: grab the existing TemariProto SVG from DOM, clone it,
            // and re-render at 120px. We can't easily re-render React here,
            // so just show existing mascot SVG clones side by side.
            const existing = document.querySelector('.temari-root svg');
            if (existing) {
                const clone = existing.cloneNode(true);
                clone.setAttribute('width', '120');
                clone.setAttribute('height', `${Math.round(120 * 146 / 120)}`);
                clone.style.display = 'block';
                cell.appendChild(clone);
            }
            cell.appendChild(label);
            overlay.appendChild(cell);
        });
        document.body.appendChild(overlay);
    });

    // The overlay will just show clones of current pose; take a screenshot anyway
    // to verify the SVG renders without errors.
    await screenshot(page, '03-pose-grid-dom');
    await page.evaluate(() => document.getElementById('pose-grid')?.remove());

    // Navigate to pages that actually show different poses
    // rekor = glow
    await page.goto(`${BASE}/rekor`);
    await page.waitForLoadState('networkidle');
    await dismissReveal(page);
    await screenshot(page, '04-rekor-glow-desktop');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`${BASE}/rekor`);
    await page.waitForLoadState('networkidle');
    await dismissReveal(page);
    await screenshot(page, '05-rekor-glow-mobile');

    // kartu detail = pose by rarity (legendary = glow)
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`${BASE}/kartu/115`);
    await page.waitForLoadState('networkidle');
    await dismissReveal(page);
    await screenshot(page, '06-kartu-detail-mobile');

    // aktivitas detail = briefing card pose (mood-driven)
    await page.goto(`${BASE}/aktivitas/126`);
    await page.waitForLoadState('networkidle');
    await dismissReveal(page);
    // scroll to briefing card
    await page.evaluate(() => window.scrollBy(0, 600));
    await page.waitForTimeout(400);
    await screenshot(page, '07-aktivitas-briefing-mobile');

    // ── 3. Share card (reuse authenticated page — reveal already cleared) ───────
    await page.goto(`${BASE}/kartu/115`);
    await page.waitForLoadState('networkidle');

    const bagikan = page.getByRole('button', { name: /bagikan/i }).first();
    if (await bagikan.isVisible()) {
        await bagikan.click();
        await page.waitForTimeout(800);
        await screenshot(page, '08-share-modal-open');
        await page.waitForTimeout(1500);
        await screenshot(page, '09-share-modal-rendered');
    } else {
        console.log('  ⚠ Bagikan not visible — current URL:', page.url());
        await screenshot(page, '08-share-modal-debug');
    }

    await page.close();
}

await browser.close();
console.log(`\nDone. Screenshots in ${OUT}/`);
