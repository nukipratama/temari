/**
 * Solid navy backing for the iOS status bar in the installed app.
 *
 * `apple-mobile-web-app-status-bar-style: black-translucent` (app.blade.php)
 * extends the web view up under the status bar and forces the clock, battery
 * and signal glyphs to render **white**, with no way to request dark ones. That
 * makes every surface reaching the top of the display responsible for staying
 * dark enough to read against — and the ones that get there are not only the
 * top bar. Every modal is `fixed inset-0` at z-50/51 and paints its own scrim
 * over that region; `CardReveal`'s is dark, but `TemariNudgeModal` and the
 * filter sheet's are not, and a light scrim under white glyphs hides the clock
 * entirely.
 *
 * So rather than auditing every present and future overlay for top-of-screen
 * contrast, one strip sits above all of them and guarantees the backing. It is
 * the same `sky` as MobileTopBar directly beneath it, so on app screens the two
 * read as a single bar rather than the banding this whole change exists to fix.
 *
 * `env(safe-area-inset-top)` collapses to 0 in a browser tab and on desktop, so
 * this renders as a zero-height no-op everywhere except the installed app.
 */
export default function StatusBarScrim() {
    return (
        <div
            aria-hidden
            data-testid="status-bar-scrim"
            className="pointer-events-none fixed inset-x-0 top-0 z-[70] h-[env(safe-area-inset-top)] bg-sky lg:hidden"
        />
    );
}
