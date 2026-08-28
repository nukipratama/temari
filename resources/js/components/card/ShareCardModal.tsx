import { Icon } from '@iconify/react';
import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useRef, useState } from 'react';

import PillButton from '@/components/ui/PillButton';
import { useModal } from '@/hooks/useModal';
import { cn } from '@/lib/cn';
import { RARITY_LABELS } from '@/lib/runcard';
import {
    COLORWAYS,
    drawShareCard,
    shareCardBlob,
    type ColorwayId,
    type Format,
    type Layout,
    type ShareKartuData,
} from '@/lib/shareCard';
import { iconButtonVariants, toggleButtonVariants } from '@/lib/variants';

export type { ShareKartuData };

interface ShareCardModalProps {
    kartu: ShareKartuData | null;
    onClose: () => void;
}

function canvasToBlob(canvas: HTMLCanvasElement): Promise<Blob> {
    return new Promise((resolve, reject) => {
        canvas.toBlob(
            (blob) =>
                blob ? resolve(blob) : reject(new Error('toBlob failed')),
            'image/png',
        );
    });
}

const LAYOUTS: Layout[] = ['kartu', 'rute', 'stats'];
const LAYOUT_LABELS: Record<Layout, string> = {
    kartu: 'Card',
    rute: 'Route',
    stats: 'Stats',
};
/** Which templates need a drawable route — the single source of truth for
 *  both the layout picker's visibility and the draw-effect's stale-selection
 *  clamp, replacing the old ad hoc `l !== 'rute'` checks in both places. */
const TEMPLATE_CAPS: Record<Layout, boolean> = {
    kartu: false,
    rute: true,
    stats: false,
};

function hasRoute(kartu: ShareKartuData): boolean {
    return kartu.polyline != null && kartu.polyline !== '';
}

const COLORWAYS_LIST: ColorwayId[] = ['navy', 'dawn', 'ember'];
const COLORWAY_LABELS: Record<ColorwayId, string> = {
    navy: 'Navy',
    dawn: 'Dawn',
    ember: 'Ember',
};

export default function ShareCardModal({
    kartu,
    onClose,
}: Readonly<ShareCardModalProps>) {
    const [layout, setLayout] = useState<Layout>('kartu');
    const [format, setFormat] = useState<Format>('story');
    const [colorway, setColorway] = useState<ColorwayId>('navy');
    // Transient status under the CTAs: confirms a copy/share that has no native
    // UI of its own, or surfaces a failure instead of swallowing it silently.
    const [status, setStatus] = useState<{
        tone: 'ok' | 'err';
        text: string;
    } | null>(null);
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const drawRef = useRef<Promise<void> | null>(null);
    const panelRef = useRef<HTMLDivElement>(null);

    useModal(kartu !== null, panelRef, onClose);

    // Repaint the fixed-resolution canvas whenever any knob changes. The canvas
    // IS the export, so the on-screen preview can never drift from the shared
    // image, and the output is identical on every device.
    useEffect(() => {
        if (kartu === null || canvasRef.current === null) {
            return;
        }
        // Clamp to a drawable layout: a no-GPS run has no route, so a stale
        // 'rute' selection (carried over from a previous GPS card) must not
        // paint a blank map.
        const drawLayout =
            !TEMPLATE_CAPS[layout] || hasRoute(kartu) ? layout : 'kartu';
        drawRef.current = drawShareCard(canvasRef.current, {
            kartu,
            layout: drawLayout,
            format,
            colorway,
        });
    }, [kartu, layout, format, colorway]);

    // Auto-clear the status line so it reads as a transient toast.
    useEffect(() => {
        if (status === null) return;
        const id = globalThis.setTimeout(() => setStatus(null), 2600);
        return () => globalThis.clearTimeout(id);
    }, [status]);

    if (kartu === null) return null;

    // Templates that need a polyline are hidden for no-GPS runs.
    const availableLayouts = hasRoute(kartu)
        ? LAYOUTS
        : LAYOUTS.filter((l) => !TEMPLATE_CAPS[l]);
    // Clamp so share/copy never export a stale 'rute' layout on a no-GPS run.
    const effectiveLayout: Layout = availableLayouts.includes(layout)
        ? layout
        : 'kartu';

    const cfg = { kartu, layout: effectiveLayout, format, colorway };

    // The preview canvas already holds the exact export bitmap at its full
    // internal resolution, so read it back rather than redrawing every template
    // into a second canvas. Awaiting the in-flight repaint first, because a tap
    // can land before it settles and would otherwise export a blank canvas.
    const captureImage = async (): Promise<Blob> => {
        await drawRef.current;
        const canvas = canvasRef.current;
        return canvas === null ? shareCardBlob(cfg) : canvasToBlob(canvas);
    };

    const handleShare = async () => {
        if (typeof navigator.share === 'function') {
            try {
                const blob = await captureImage();
                const file = new File([blob], `${kartu.name}.png`, {
                    type: 'image/png',
                });
                if (navigator.canShare?.({ files: [file] })) {
                    await navigator.share({
                        files: [file],
                        title: `${kartu.name} · Temari`,
                    });
                    return;
                }
            } catch {
                // fall through to URL share
            }
        }
        const url = kartu.shareUrl;
        if (typeof navigator.share === 'function') {
            try {
                await navigator.share({
                    title: `${kartu.name} · Temari`,
                    text:
                        kartu.quote ??
                        `${RARITY_LABELS[kartu.rarity]} card: ${kartu.name}`,
                    url,
                });
            } catch {
                // user cancelled or API unavailable
            }
        } else if (navigator.clipboard?.writeText !== undefined) {
            try {
                await navigator.clipboard.writeText(url);
                setStatus({ tone: 'ok', text: 'Activity link copied.' });
            } catch {
                setStatus({ tone: 'err', text: 'Failed to copy link.' });
            }
        } else {
            setStatus({
                tone: 'err',
                text: "This browser doesn't support sharing.",
            });
        }
    };

    const handleCopy = async () => {
        if (
            typeof ClipboardItem === 'undefined' ||
            navigator.clipboard?.write === undefined
        ) {
            setStatus({
                tone: 'err',
                text: "This browser doesn't support copying images. Use Share instead.",
            });
            return;
        }
        try {
            const blob = await captureImage();
            await navigator.clipboard.write([
                new ClipboardItem({ 'image/png': blob }),
            ]);
            setStatus({ tone: 'ok', text: 'Card image copied.' });
        } catch {
            setStatus({
                tone: 'err',
                text: 'Failed to copy image. Try Share instead.',
            });
        }
    };

    return (
        <AnimatePresence>
            <motion.div
                key="share-backdrop"
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                exit={{ opacity: 0 }}
                className="fixed inset-0 z-[51] flex items-center justify-center p-4"
                style={{
                    background: 'rgba(0,0,0,0.5)',
                    backdropFilter: 'blur(6px)',
                }}
            >
                <motion.div
                    key="share-panel"
                    ref={panelRef}
                    role="dialog"
                    aria-modal="true"
                    initial={{ opacity: 0, scale: 0.96, y: 8 }}
                    animate={{ opacity: 1, scale: 1, y: 0 }}
                    exit={{ opacity: 0, scale: 0.96, y: 8 }}
                    transition={{ duration: 0.3, ease: [0.22, 1, 0.36, 1] }}
                    className="flex w-full max-w-md flex-col overflow-hidden rounded-xl bg-cream shadow-e4"
                    style={{ maxHeight: '92dvh' }}
                >
                    {/* Header — pinned. */}
                    <div className="flex items-center gap-3 border-b border-cream-deep px-5 py-3.5">
                        <button
                            type="button"
                            onClick={onClose}
                            aria-label="Close"
                            className={iconButtonVariants({ size: 'sm' })}
                        >
                            <Icon icon="mdi:close" width={16} height={16} />
                        </button>
                        <div className="flex-1 text-center">
                            <div className="text-label-micro text-text-2">
                                Share card
                            </div>
                            <div className="font-serif text-xl tracking-tight text-foreground">
                                {kartu.name}
                            </div>
                        </div>
                        <div className="w-8" />
                    </div>

                    {/* Body — preview + pickers, scrolls on short screens. */}
                    <div className="flex flex-1 flex-col items-center gap-4 overflow-y-auto bg-cream-deep px-5 py-5">
                        {/* Preview canvas — fixed internal resolution, bounded by HEIGHT so
                            a tall 9:16 story scales to fit instead of being forced to the
                            column width. Width derives from the canvas's intrinsic ratio, so
                            the bitmap is never distorted. This canvas IS the exported image. */}
                        <canvas
                            ref={canvasRef}
                            width={1080}
                            height={format === 'story' ? 1920 : 1080}
                            aria-label={`Preview of ${kartu.name}`}
                            className="block rounded-lg"
                            style={{ maxWidth: '100%', maxHeight: '52vh' }}
                        />

                        {/* Format picker */}
                        <div className="grid w-full grid-cols-2 gap-2">
                            {(['story', 'feed'] as Format[]).map((f) => (
                                <button
                                    key={f}
                                    type="button"
                                    onClick={() => setFormat(f)}
                                    aria-pressed={format === f}
                                    className={cn(
                                        'focus-ring flex items-center justify-center gap-2 rounded-xl p-2.5 text-xs font-medium transition',
                                        format === f
                                            ? 'border-2 border-sky bg-cream font-semibold text-foreground'
                                            : 'border-2 border-transparent bg-cream text-text-2 hover:border-cream-deep',
                                    )}
                                >
                                    <span
                                        aria-hidden
                                        className={cn(
                                            'rounded-sm bg-sky/25',
                                            f === 'story'
                                                ? 'h-6 w-3.5'
                                                : 'h-5 w-5',
                                        )}
                                    />
                                    {f === 'story'
                                        ? 'Portrait · 9:16'
                                        : 'Square · 1:1'}
                                </button>
                            ))}
                        </div>

                        {/* Style — template picker. Hidden only if a single layout remains
                            to choose from (e.g. Route needs a polyline the run doesn't have). */}
                        {availableLayouts.length > 1 && (
                            <div className="flex w-full gap-2">
                                {availableLayouts.map((l) => (
                                    <button
                                        key={l}
                                        type="button"
                                        onClick={() => setLayout(l)}
                                        aria-pressed={effectiveLayout === l}
                                        className={cn(
                                            toggleButtonVariants({
                                                selected: effectiveLayout === l,
                                                size: 'md',
                                            }),
                                            'flex-1',
                                        )}
                                    >
                                        {LAYOUT_LABELS[l]}
                                    </button>
                                ))}
                            </div>
                        )}

                        {/* Colorway — swatch picker. Always 3 choices; unlike the
                            template picker, colorway never depends on run data. */}
                        <div className="flex w-full items-center justify-center gap-3">
                            {COLORWAYS_LIST.map((c) => (
                                <button
                                    key={c}
                                    type="button"
                                    onClick={() => setColorway(c)}
                                    aria-pressed={colorway === c}
                                    aria-label={`Colorway: ${COLORWAY_LABELS[c]}`}
                                    className={cn(
                                        'focus-ring h-9 w-9 rounded-full border-2 transition',
                                        colorway === c
                                            ? 'border-sky'
                                            : 'border-transparent hover:border-cream-deep',
                                    )}
                                >
                                    <span
                                        aria-hidden
                                        className="block h-full w-full rounded-full border border-black/10"
                                        style={{
                                            background: COLORWAYS[c].surface,
                                        }}
                                    />
                                </button>
                            ))}
                        </div>
                    </div>

                    {/* CTAs — pinned footer. */}
                    <div className="flex flex-col gap-2 border-t border-cream-deep bg-cream px-5 py-4">
                        <PillButton
                            tone="sky"
                            onClick={handleShare}
                            className="w-full justify-center py-3.5 font-semibold"
                        >
                            <Icon
                                icon="mdi:share-variant"
                                width={16}
                                height={16}
                                aria-hidden
                            />
                            Share
                        </PillButton>
                        <PillButton
                            tone="ghost"
                            onClick={handleCopy}
                            className="w-full justify-center"
                        >
                            <Icon
                                icon="mdi:content-copy"
                                width={16}
                                height={16}
                                aria-hidden
                            />
                            Copy image
                        </PillButton>
                        {status !== null && (
                            <p
                                role="status"
                                aria-live="polite"
                                className={cn(
                                    'text-center font-sans text-xs',
                                    status.tone === 'ok'
                                        ? 'text-leaf-ink'
                                        : 'text-ember-ink',
                                )}
                            >
                                {status.text}
                            </p>
                        )}
                    </div>
                </motion.div>
            </motion.div>
        </AnimatePresence>
    );
}
