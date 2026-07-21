import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useRef, useState } from 'react';
import { Icon } from '@iconify/react';
import { cn } from '@/lib/cn';
import { useDismissable } from '@/hooks/useDismissable';
import { useFocusTrap } from '@/hooks/useFocusTrap';
import { useBodyScrollLock } from '@/hooks/useBodyScrollLock';
import PillButton from '@/components/ui/PillButton';
import { iconButtonVariants, toggleButtonVariants } from '@/lib/variants';
import { RARITY_LABELS } from '@/lib/runcard';
import { drawShareCard, shareCardBlob, type Format, type Layout, type ShareKartuData } from '@/lib/shareCard';

export type { ShareKartuData };

interface ShareCardModalProps {
    kartu: ShareKartuData | null;
    onClose: () => void;
}

const LAYOUTS: Layout[] = ['kartu', 'rute'];
const LAYOUT_LABELS: Record<Layout, string> = {
    kartu: 'Kartu',
    rute: 'Rute',
};

export default function ShareCardModal({ kartu, onClose }: Readonly<ShareCardModalProps>) {
    const [layout, setLayout] = useState<Layout>('kartu');
    const [format, setFormat] = useState<Format>('story');
    // Transient status under the CTAs: confirms a copy/share that has no native
    // UI of its own, or surfaces a failure instead of swallowing it silently.
    const [status, setStatus] = useState<{ tone: 'ok' | 'err'; text: string } | null>(null);
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const panelRef = useRef<HTMLDivElement>(null);

    useDismissable(kartu !== null, panelRef, onClose);
    useFocusTrap(kartu !== null, panelRef);
    useBodyScrollLock(kartu !== null);

    // Repaint the fixed-resolution canvas whenever any knob changes. The canvas
    // IS the export, so the on-screen preview can never drift from the shared
    // image, and the output is identical on every device.
    useEffect(() => {
        if (kartu === null || canvasRef.current === null) {
            return;
        }
        // Clamp to a drawable layout: a no-GPS run has no route, so a stale
        // 'rute' selection (carried over from a previous GPS card) must not
        // paint a blank map. See `hasRoute` below.
        const drawLayout = kartu.polyline != null && kartu.polyline !== '' ? layout : 'kartu';
        void drawShareCard(canvasRef.current, { kartu, layout: drawLayout, format });
    }, [kartu, layout, format]);

    // Auto-clear the status line so it reads as a transient toast.
    useEffect(() => {
        if (status === null) return;
        const id = globalThis.setTimeout(() => setStatus(null), 2600);
        return () => globalThis.clearTimeout(id);
    }, [status]);

    if (kartu === null) return null;

    // The route-hero template needs a polyline; hide it for no-GPS runs.
    const hasRoute = kartu.polyline != null && kartu.polyline !== '';
    const availableLayouts = hasRoute ? LAYOUTS : LAYOUTS.filter((l) => l !== 'rute');
    // Clamp so share/copy never export a stale 'rute' layout on a no-GPS run.
    const effectiveLayout: Layout = availableLayouts.includes(layout) ? layout : 'kartu';

    const cfg = { kartu, layout: effectiveLayout, format };

    const captureImage = (): Promise<Blob> => shareCardBlob(cfg);

    const handleShare = async () => {
        if (typeof navigator.share === 'function') {
            try {
                const blob = await captureImage();
                const file = new File([blob], `${kartu.name}.png`, { type: 'image/png' });
                if (navigator.canShare?.({ files: [file] })) {
                    await navigator.share({ files: [file], title: `${kartu.name} · Temari` });
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
                    text: kartu.quote ?? `Kartu ${RARITY_LABELS[kartu.rarity]}: ${kartu.name}`,
                    url,
                });
            } catch {
                // user cancelled or API unavailable
            }
        } else if (navigator.clipboard?.writeText !== undefined) {
            try {
                await navigator.clipboard.writeText(url);
                setStatus({ tone: 'ok', text: 'Link aktivitas kesalin.' });
            } catch {
                setStatus({ tone: 'err', text: 'Gagal nyalin link.' });
            }
        } else {
            setStatus({ tone: 'err', text: 'Browser ini belum dukung berbagi.' });
        }
    };

    const handleCopy = async () => {
        if (typeof ClipboardItem === 'undefined' || navigator.clipboard?.write === undefined) {
            setStatus({ tone: 'err', text: 'Browser ini belum dukung salin gambar. Pakai Bagikan ya.' });
            return;
        }
        try {
            const blob = await captureImage();
            await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
            setStatus({ tone: 'ok', text: 'Gambar kartu kesalin.' });
        } catch {
            setStatus({ tone: 'err', text: 'Gagal nyalin gambar. Coba Bagikan aja.' });
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
                style={{ background: 'rgba(0,0,0,0.5)', backdropFilter: 'blur(6px)' }}
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
                    className="flex w-full max-w-md flex-col overflow-hidden rounded-3xl bg-cream shadow-2xl"
                    style={{ maxHeight: '92dvh' }}
                >
                    {/* Header — pinned. */}
                    <div className="flex items-center gap-3 border-b border-cream-deep px-5 py-3.5">
                        <button
                            type="button"
                            onClick={onClose}
                            aria-label="Tutup"
                            className={iconButtonVariants({ size: 'sm' })}
                        >
                            <Icon icon="mdi:close" width={16} height={16} />
                        </button>
                        <div className="flex-1 text-center">
                            <div className="font-mono font-bold text-[11px] uppercase tracking-[0.14em] text-ink-2">
                                Bagikan kartu
                            </div>
                            <div className="font-display text-xl tracking-tight text-ink">
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
                            aria-label={`Pratinjau kartu ${kartu.name}`}
                            className="block rounded-2xl"
                            style={{ maxWidth: '100%', maxHeight: '52vh', boxShadow: '0 16px 48px rgba(31,39,71,0.25)' }}
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
                                            ? 'border-2 border-sky bg-cream font-semibold text-ink'
                                            : 'border-2 border-transparent bg-cream text-ink-2 hover:border-cream-deep',
                                    )}
                                >
                                    <span
                                        aria-hidden
                                        className={cn(
                                            'rounded-sm bg-sky/25',
                                            f === 'story' ? 'h-6 w-3.5' : 'h-5 w-5',
                                        )}
                                    />
                                    {f === 'story' ? 'Potret · 9:16' : 'Persegi · 1:1'}
                                </button>
                            ))}
                        </div>

                        {/* Gaya — template picker. Hidden when a no-GPS run leaves only
                            the Kartu layout, so there's nothing to choose. */}
                        {availableLayouts.length > 1 && (
                            <div className="flex w-full gap-2">
                                {availableLayouts.map((l) => (
                                    <button
                                        key={l}
                                        type="button"
                                        onClick={() => setLayout(l)}
                                        aria-pressed={layout === l}
                                        className={cn(toggleButtonVariants({ selected: layout === l, size: 'md' }), 'flex-1')}
                                    >
                                        {LAYOUT_LABELS[l]}
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* CTAs — pinned footer. */}
                    <div className="flex flex-col gap-2 border-t border-cream-deep bg-cream px-5 py-4">
                        <PillButton tone="sky" onClick={handleShare} className="w-full justify-center py-3.5 font-semibold">
                            <Icon icon="mdi:share-variant" width={16} height={16} aria-hidden />
                            Bagikan
                        </PillButton>
                        <PillButton tone="ghost" onClick={handleCopy} className="w-full justify-center">
                            <Icon icon="mdi:content-copy" width={16} height={16} aria-hidden />
                            Salin gambar
                        </PillButton>
                        {status !== null && (
                            <p
                                role="status"
                                aria-live="polite"
                                className={cn(
                                    'text-center font-sans text-xs',
                                    status.tone === 'ok' ? 'text-leaf-deep' : 'text-ember-deep',
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
