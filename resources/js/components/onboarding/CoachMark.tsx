import {
    type CSSProperties,
    type RefObject,
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

function positionFor(
    anchor: DOMRect,
    placement: CoachMarkPlacement,
): CSSProperties {
    switch (placement) {
        case 'top':
            return {
                left: anchor.left + anchor.width / 2,
                top: anchor.top - GAP,
                transform: 'translate(-50%, -100%)',
            };
        case 'left':
            return {
                left: anchor.left - GAP,
                top: anchor.top + anchor.height / 2,
                transform: 'translate(-100%, -50%)',
            };
        case 'right':
            return {
                left: anchor.right + GAP,
                top: anchor.top + anchor.height / 2,
                transform: 'translateY(-50%)',
            };
        case 'bottom':
        default:
            return {
                left: anchor.left + anchor.width / 2,
                top: anchor.bottom + GAP,
                transform: 'translateX(-50%)',
            };
    }
}

/**
 * A dismissible callout anchored to an arbitrary DOM element elsewhere on the
 * page (pass its ref via `anchorRef`), rendered through a portal so it isn't
 * bound by the anchor's own overflow/stacking context. Dismissal is
 * permanent per user via {@link useCoachMark}.
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
    const [style, setStyle] = useState<CSSProperties | null>(null);

    usePopover(visible, containerRef, dismiss);

    useLayoutEffect(() => {
        if (!visible) {
            return;
        }
        const anchor = anchorRef.current;
        if (anchor === null) {
            return;
        }

        const reposition = () => {
            setStyle({
                position: 'fixed',
                ...positionFor(anchor.getBoundingClientRect(), placement),
            });
        };
        const raf = requestAnimationFrame(reposition);

        window.addEventListener('resize', reposition);
        window.addEventListener('scroll', reposition, true);
        return () => {
            cancelAnimationFrame(raf);
            window.removeEventListener('resize', reposition);
            window.removeEventListener('scroll', reposition, true);
        };
    }, [visible, anchorRef, placement]);

    if (!visible || style === null || typeof document === 'undefined') {
        return null;
    }

    return createPortal(
        <div
            ref={containerRef}
            role="dialog"
            aria-label={title}
            style={style}
            className={cn(
                'z-50 w-64 rounded-2xl border border-line bg-surface-elev p-4 shadow-lg',
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
                className="focus-ring mt-3 text-label-micro text-horizon-deep"
            >
                Got it
            </button>
        </div>,
        document.body,
    );
}
