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
// The mobile bottom nav is fixed over the page below `lg`.
const BOTTOM_INSET = 76;
const WIDTH = 256;
const FALLBACK_HEIGHT = 150;

function clamp(value: number, min: number, max: number): number {
    return Math.min(Math.max(value, min), Math.max(min, max));
}

const OPPOSITE: Record<CoachMarkPlacement, CoachMarkPlacement> = {
    top: 'bottom',
    bottom: 'top',
    left: 'right',
    right: 'left',
};

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

function positionFor(
    anchor: DOMRect,
    placement: CoachMarkPlacement,
    height: number,
): CSSProperties {
    const requested = offsetFor(anchor, placement, height);
    const flipped = offsetFor(anchor, OPPOSITE[placement], height);
    const chosen =
        fitsOnScreen(requested, height) || !fitsOnScreen(flipped, height)
            ? requested
            : flipped;

    return {
        position: 'fixed',
        left: clamp(chosen.left, MARGIN, window.innerWidth - WIDTH - MARGIN),
        top: clamp(
            chosen.top,
            MARGIN,
            window.innerHeight - height - BOTTOM_INSET,
        ),
    };
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
                ),
            );
        };
        const raf = requestAnimationFrame(reposition);

        window.addEventListener('resize', reposition);
        window.addEventListener('scroll', reposition, true);
        return () => {
            cancelAnimationFrame(raf);
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
                'z-50 w-64 rounded-lg border border-line bg-surface-elev p-4 shadow-e2',
                className,
            )}
        >
            <p className="font-display text-sm font-semibold text-ink">
                {title}
            </p>
            {body && <p className="mt-1.5 text-sm text-ink-2">{body}</p>}
            <button
                type="button"
                onClick={dismiss}
                className="focus-ring mt-3 text-label-micro text-horizon-ink"
            >
                Got it
            </button>
        </div>,
        document.body,
    );
}
