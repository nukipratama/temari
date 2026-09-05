/**
 * Paints the strip above the top bar in the ground colour.
 *
 * Whether that strip belongs to the app depends on how the web app was
 * installed: from Chrome the web view runs edge to edge and page content shows
 * through behind the clock, so without this the briefing text bleeds into the
 * status bar and into the gap above the chips. From Safari iOS covers the
 * region itself and this is a no-op. Cheap enough to keep for the case that
 * needs it.
 */
export default function StatusBarScrim() {
    return (
        <div
            aria-hidden
            data-testid="status-bar-scrim"
            className="pointer-events-none fixed inset-x-0 top-0 z-40 h-[env(safe-area-inset-top)] bg-background"
        />
    );
}
