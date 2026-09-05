/**
 * Paints the strip iOS hands us under `black-translucent`. Safari 26 derives
 * the status-bar treatment from the background of the topmost element in the
 * viewport; MobileTopBar deliberately paints nothing, so without this iOS finds
 * no colour and falls back to its own glass material. Bounded to the inset, and
 * so above the top bar (z-40 over z-30) without ever reaching its chips, which
 * start below the inset and keep floating over scrolling content.
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
