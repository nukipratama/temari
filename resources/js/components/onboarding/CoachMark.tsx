import {
    type CSSProperties,
    type RefObject,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import { createPortal } from 'react-dom';

import { useCoachMark } from '@/hooks/useCoachMark';
import { usePopover } from '@/hooks/usePopover';
import { cn } from '@/lib/cn';

export type CoachMarkPlacement = 'top' | 'bottom' | 'left' | 'right';

interface CoachMarkProps {
    /** Stable identifier — dismissal is remembered per user under this id. */
    id: string;
    anchorRef: RefObject<HTMLElement | null>;
    title: string;
    body?: string;
    placement?: CoachMarkPlacement;
    className?: string;
}

const GAP = 12;
const MARGIN = 12;
// The mobile bottom nav is fixed over the page below `lg`. Mirrors the height
// MobileBottomNav actually renders at (pt-2.5 + content + pb-7); anything less
// parks the mark underneath it.
const BOTTOM_INSET = 96;
const WIDTH = 256;
const FALLBACK_HEIGHT = 150;

function clamp(value: number, min: number, max: number): number {
    return Math.min(Math.max(value, min), Math.max(min, max));
}

function offsetFor(
    anchor: DOMRect,
    placement: CoachMarkPlacement,
    height: number,
): { left: number; top: number } {
    const centerX = anchor.left + anchor.width / 2 - WIDTH / 2;
    const centerY = anchor.top + anchor.height / 2 - height / 2;

    switch (placement) {
        case 'top':
            return { left: centerX, top: anchor.top - GAP - height };
        case 'left':
            return { left: anchor.left - GAP - WIDTH, top: centerY };
        case 'right':
            return { left: anchor.right + GAP, top: centerY };
        case 'bottom':
        default:
            return { left: centerX, top: anchor.bottom + GAP };
    }
}

function fitsOnScreen(
    { left, top }: { left: number; top: number },
    height: number,
): boolean {
    return (
        left >= MARGIN &&
        left + WIDTH <= window.innerWidth - MARGIN &&
        top >= MARGIN &&
        top + height <= window.innerHeight - BOTTOM_INSET
    );
}

export interface Obstacle {
    left: number;
    top: number;
    right: number;
    bottom: number;
}

/** How much of `o` the mark would sit on top of, 0..1. */
function coveredFraction(
    { left, top }: { left: number; top: number },
    height: number,
    o: Obstacle,
): number {
    const w = Math.min(left + WIDTH, o.right) - Math.max(left, o.left);
    const h = Math.min(top + height, o.bottom) - Math.max(top, o.top);
    const area = (o.right - o.left) * (o.bottom - o.top);
    return w > 0 && h > 0 && area > 0 ? (w * h) / area : 0;
}

// Total overlap area is the wrong metric: it prefers burying two small nav tabs
// over clipping the edges of a large sparse grid. Only near-total coverage
// actually destroys a control, so that is what the score counts.
const BURIED_AT = 0.8;

function buriedCount(
    box: { left: number; top: number },
    height: number,
    obstacles: ReadonlyArray<Obstacle>,
): number {
    return obstacles.filter((o) => coveredFraction(box, height, o) >= BURIED_AT)
        .length;
}

const ORDER: Record<CoachMarkPlacement, ReadonlyArray<CoachMarkPlacement>> = {
    top: ['top', 'bottom', 'right', 'left'],
    bottom: ['bottom', 'top', 'right', 'left'],
    left: ['left', 'right', 'bottom', 'top'],
    right: ['right', 'left', 'bottom', 'top'],
};

/**
 * Picks the placement that stays on screen *and* buries the least of whatever
 * the caller passed as `obstacles` — a coach mark that fully covers the control
 * it is describing explains nothing. Falls back to the requested placement,
 * clamped into the viewport, when every candidate collides.
 */
export function positionFor(
    anchor: DOMRect,
    placement: CoachMarkPlacement,
    height: number,
    obstacles: ReadonlyArray<Obstacle> = [],
): CSSProperties {
    const candidates = ORDER[placement].map((p) => {
        const raw = offsetFor(anchor, p, height);
        const box = {
            left: clamp(raw.left, MARGIN, window.innerWidth - WIDTH - MARGIN),
            top: clamp(
                raw.top,
                MARGIN,
                window.innerHeight - height - BOTTOM_INSET,
            ),
        };
        return {
            box,
            fits: fitsOnScreen(raw, height) ? 0 : 1,
            buried: buriedCount(box, height, obstacles),
            overlap: obstacles.reduce(
                (sum, o) => sum + coveredFraction(box, height, o),
                0,
            ),
        };
    });

    const best = candidates.reduce((a, b) => {
        if (b.buried !== a.buried) {
            return b.buried < a.buried ? b : a;
        }
        if (b.fits !== a.fits) {
            return b.fits < a.fits ? b : a;
        }
        return b.overlap < a.overlap ? b : a;
    });

    return { position: 'fixed', left: best.box.left, top: best.box.top };
}

const OBSTACLE_SELECTOR = 'a[href], button, [role="tab"], h1, h2, h3';

function visibleObstacles(self: HTMLElement | null): Obstacle[] {
    const out: Obstacle[] = [];
    for (const el of document.querySelectorAll(OBSTACLE_SELECTOR)) {
        if (self?.contains(el) === true) {
            continue;
        }
        const r = el.getBoundingClientRect();
        if (
            r.width > 0 &&
            r.height > 0 &&
            r.bottom > 0 &&
            r.top < window.innerHeight
        ) {
            out.push({
                left: r.left,
                top: r.top,
                right: r.right,
                bottom: r.bottom,
            });
        }
    }
    return out;
}

/**
 * A dismissible callout anchored to an arbitrary DOM element elsewhere on the
 * page (pass its ref via `anchorRef`), rendered through a portal so it isn't
 * bound by the anchor's own overflow/stacking context. It waits for its anchor
 * to scroll into view, then parks itself inside the viewport whatever the
 * requested placement. Dismissal is permanent per user via {@link useCoachMark}.
 */
export default function CoachMark({
    id,
    anchorRef,
    title,
    body,
    placement = 'bottom',
    className,
}: Readonly<CoachMarkProps>) {
    const { visible, dismiss } = useCoachMark(id);
    const containerRef = useRef<HTMLDivElement>(null);
    const [anchorOnScreen, setAnchorOnScreen] = useState(false);
    const [style, setStyle] = useState<CSSProperties | null>(null);
    const [closed, setClosed] = useState(false);

    const shown = visible && anchorOnScreen && !closed;
    // Outside click / Escape just hides it for this visit — only the explicit
    // "Got it" button below persists the dismissal via useCoachMark.
    usePopover(shown, containerRef, () => setClosed(true));

    useEffect(() => {
        const anchor = anchorRef.current;
        if (!visible || anchor === null) {
            return;
        }

        const observer = new IntersectionObserver(
            (entries) =>
                setAnchorOnScreen(entries.some((e) => e.isIntersecting)),
            { threshold: 0.1 },
        );
        observer.observe(anchor);
        return () => observer.disconnect();
    }, [visible, anchorRef]);

    useLayoutEffect(() => {
        const anchor = anchorRef.current;
        if (!shown || anchor === null) {
            return;
        }

        const reposition = () => {
            setStyle(
                positionFor(
                    anchor.getBoundingClientRect(),
                    placement,
                    containerRef.current?.offsetHeight ?? FALLBACK_HEIGHT,
                    visibleObstacles(containerRef.current),
                ),
            );
        };

        // The first pass necessarily runs before the mark is mounted, so it
        // measures with FALLBACK_HEIGHT and cannot exclude the mark's own
        // controls from the obstacle set. The second frame redoes it for real,
        // and the observer catches any later shift (lazy content, font swap).
        const observer = new ResizeObserver(reposition);
        observer.observe(document.documentElement);
        observer.observe(anchor);

        let second = 0;
        const first = requestAnimationFrame(() => {
            reposition();
            second = requestAnimationFrame(() => {
                if (containerRef.current !== null) {
                    observer.observe(containerRef.current);
                }
                reposition();
            });
        });

        window.addEventListener('resize', reposition);
        window.addEventListener('scroll', reposition, true);
        return () => {
            cancelAnimationFrame(first);
            cancelAnimationFrame(second);
            observer.disconnect();
            window.removeEventListener('resize', reposition);
            window.removeEventListener('scroll', reposition, true);
        };
    }, [shown, anchorRef, placement]);

    if (!shown || style === null || typeof document === 'undefined') {
        return null;
    }

    return createPortal(
        <div
            ref={containerRef}
            role="dialog"
            aria-label={title}
            style={style}
            className={cn(
                'z-50 w-64 rounded-lg border border-border bg-popover p-4 shadow-e2',
                className,
            )}
        >
            <p className="font-serif text-sm font-semibold text-foreground">
                {title}
            </p>
            {body && <p className="mt-1.5 text-sm text-text-2">{body}</p>}
            <button
                type="button"
                onClick={dismiss}
                className="focus-ring -mx-1 mt-2 inline-flex min-h-6 items-center rounded px-1 text-label-micro text-horizon-ink"
            >
                Got it
            </button>
        </div>,
        document.body,
    );
}
