import { type CSSProperties, useCallback, useLayoutEffect, useState } from 'react';
import Portal from '@/components/ui/Portal';
import Temari from '@/components/temari/Temari';

export interface TourStep {
    target: string;
    title: string;
    body: string;
    tipSide: 'above' | 'below' | 'left' | 'right';
}

interface GuidedTourProps {
    steps: TourStep[];
    storageKey: string;
    forceShow?: boolean;
}

const TOOLTIP_W = 288;
const SPOTLIGHT_PAD = 8;
const TOOLTIP_OFFSET = 14;

function shouldShow(storageKey: string, forceShow: boolean): boolean {
    if (forceShow) return true;
    try {
        return globalThis.localStorage?.getItem(storageKey) !== 'true';
    } catch {
        return false;
    }
}

export default function GuidedTour({
    steps,
    storageKey,
    forceShow = false,
}: Readonly<GuidedTourProps>) {
    const [visible, setVisible] = useState(() => shouldShow(storageKey, forceShow));
    const [step, setStep] = useState(0);
    const [rect, setRect] = useState<DOMRect | null>(null);

    const currentStep = steps[step] ?? null;

    const measureTarget = useCallback(() => {
        if (!currentStep) return;
        const el = document.querySelector<HTMLElement>(`[data-tour="${currentStep.target}"]`);
        if (el) {
            setRect(el.getBoundingClientRect());
        } else {
            setRect(null);
        }
    }, [currentStep]);

    useLayoutEffect(() => {
        if (!visible || !currentStep) return;

        const el = document.querySelector<HTMLElement>(`[data-tour="${currentStep.target}"]`);
        const elRect = el?.getBoundingClientRect();
        if (!el || (elRect!.width === 0 && elRect!.height === 0)) {
            // Target not in DOM or hidden on this viewport (e.g. mobile-only nav on desktop)
            setStep((s) => (s + 1 < steps.length ? s + 1 : s));
            return;
        }

        el.scrollIntoView?.({ behavior: 'smooth', block: 'nearest' });

        setRect(elRect!);

        const observer = new ResizeObserver(measureTarget);
        observer.observe(el);
        window.addEventListener('scroll', measureTarget, { passive: true });
        window.addEventListener('resize', measureTarget, { passive: true });
        return () => {
            observer.disconnect();
            window.removeEventListener('scroll', measureTarget);
            window.removeEventListener('resize', measureTarget);
        };
    }, [visible, currentStep, steps.length, measureTarget]);

    const dismiss = useCallback(() => {
        if (!forceShow) {
            try {
                globalThis.localStorage?.setItem(storageKey, 'true');
            } catch {
                // localStorage unavailable
            }
        }
        setVisible(false);
    }, [forceShow, storageKey]);

    const advance = useCallback(() => {
        if (step < steps.length - 1) {
            setStep((s) => s + 1);
        } else {
            dismiss();
        }
    }, [step, steps.length, dismiss]);

    if (!visible || !rect || !currentStep) return null;

    const vw = window.innerWidth;
    const vh = window.innerHeight;

    const x = rect.left - SPOTLIGHT_PAD;
    const y = rect.top - SPOTLIGHT_PAD;
    const w = rect.width + SPOTLIGHT_PAD * 2;
    const h = rect.height + SPOTLIGHT_PAD * 2;

    const clampedX = Math.max(12, Math.min(vw - TOOLTIP_W - 12, x + w / 2 - TOOLTIP_W / 2));

    const tooltipStyle: CSSProperties = (() => {
        switch (currentStep.tipSide) {
            case 'above':
                return { bottom: vh - y + TOOLTIP_OFFSET, left: clampedX, width: TOOLTIP_W };
            case 'below':
                return { top: y + h + TOOLTIP_OFFSET, left: clampedX, width: TOOLTIP_W };
            case 'left':
                return { top: Math.max(12, y + h / 2 - 64), right: vw - x + TOOLTIP_OFFSET, width: TOOLTIP_W };
            case 'right':
                return { top: Math.max(12, y + h / 2 - 64), left: x + w + TOOLTIP_OFFSET, width: TOOLTIP_W };
        }
    })();

    // Portal so the overlay escapes the page's enter-animation stacking context
    // (see Portal) and lands above the bottom nav.
    return (
        <Portal>
            {/* 4-panel backdrop */}
            <div aria-hidden className="pointer-events-none fixed inset-0 z-[49]">
                <div className="fixed bg-ink/60" style={{ top: 0, left: 0, right: 0, height: Math.max(0, y) }} />
                <div className="fixed bg-ink/60" style={{ top: y + h, left: 0, right: 0, bottom: 0 }} />
                <div className="fixed bg-ink/60" style={{ top: y, left: 0, width: Math.max(0, x), height: h }} />
                <div className="fixed bg-ink/60" style={{ top: y, left: x + w, right: 0, height: h }} />
            </div>

            {/* Tooltip */}
            <div
                role="dialog"
                aria-label={currentStep.title}
                className="fixed z-[50] rounded-2xl bg-cream p-4 shadow-xl ring-1 ring-ink/[0.06]"
                style={tooltipStyle}
            >
                <div className="flex items-start gap-3">
                    <Temari pose="observational" size={44} />
                    <div className="min-w-0 flex-1">
                        <div className="mb-1 font-mono text-[9px] font-bold uppercase tracking-[0.16em] text-ink-3">
                            {step + 1} / {steps.length}
                        </div>
                        <h3 className="font-display text-lg leading-tight tracking-tight text-ink">{currentStep.title}</h3>
                        <p className="mt-1.5 font-sans text-sm leading-relaxed text-ink-2">
                            &ldquo;{currentStep.body}&rdquo;
                        </p>
                        <div className="mt-3 flex items-center gap-3">
                            <button
                                type="button"
                                onClick={advance}
                                className="rounded-full bg-horizon px-4 py-1.5 font-sans text-xs font-semibold text-sky transition hover:opacity-90"
                            >
                                {step < steps.length - 1 ? 'Lanjut →' : 'Selesai'}
                            </button>
                            <button
                                type="button"
                                onClick={dismiss}
                                className="font-mono text-[10px] uppercase tracking-[0.1em] text-ink-3 transition hover:text-ink"
                            >
                                Lewati
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Portal>
    );
}
