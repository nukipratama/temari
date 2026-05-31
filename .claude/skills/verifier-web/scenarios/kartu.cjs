// Example scenario: verify the Kartu collection, a card detail, and every
// share template (full-resolution canvas). Copy this as a starting point.
// Run: sh .claude/skills/verifier-web/run.sh .claude/skills/verifier-web/scenarios/kartu.cjs
module.exports = async (page, h) => {
    await h.go('/kartu');
    await h.shot('01-kartu-grid');

    await page.locator('a[href^="/kartu/"]').first().click();
    await page.waitForLoadState('networkidle');
    await h.shot('02-kartu-detail');

    await page.getByRole('button', { name: /Bagikan/ }).first().click();
    await page.waitForTimeout(1200);
    const select = page.getByLabel('Pilih gaya kartu');
    console.log('GAYA:', JSON.stringify(await select.locator('option').allTextContents()));
    for (const layout of ['kartu', 'pack', 'rute', 'polaroid', 'poster', 'struk']) {
        try {
            await select.selectOption(layout);
            await page.waitForTimeout(1200);
            await h.dumpCanvas(`share-${layout}`); // full-res, NOT the modal thumbnail
        } catch (e) {
            console.log('skip', layout, '-', e.message);
        }
    }
};
